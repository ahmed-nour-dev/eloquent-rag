<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDependency;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Ahmednour\EloquentRag\Tests\Integration\MariaDbAcceptanceTestCase;
use Ahmednour\EloquentRag\VectorBackendCapability;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

/**
 * Real-MariaDB-11.7+ acceptance suite (Phase 5). Requires the RAG_TEST_MARIADB_*
 * env vars (see .github/workflows/tests.yml's mariadb job); skips cleanly
 * everywhere else, including this sandbox, since no real MariaDB 11.7+ is
 * available here.
 *
 * This is the first place in the whole test suite that can prove
 * VectorBackendCapability's *acceptance* path (every earlier phase could
 * only prove *rejection*, via the real-but-unsupported SQLite connection,
 * or use mocks), the AsVector round-trip against a genuine vector column,
 * and rag:doctor's dimension-introspection SQL against real
 * information_schema output.
 *
 * Embeddings::fake() is used here too — real semantic embedding calls need
 * AWS Bedrock credentials, an entirely separate concern from the database
 * backend this suite exists to validate. That means the search test below
 * can only prove the query *mechanics* execute correctly against a real
 * vector column (join, orderByVectorDistance, hydration back to the owner
 * model) — it cannot prove semantic relevance ordering, since the vectors
 * involved carry no real meaning either way, in this sandbox or in the
 * real GitHub Actions run.
 */
beforeEach(function () {
    if (! MariaDbAcceptanceTestCase::isConfigured()) {
        $this->markTestSkipped(
            'RAG_TEST_MARIADB_HOST not set — no real MariaDB 11.7+ available. '.
            'Set the RAG_TEST_MARIADB_* env vars (see .github/workflows/tests.yml) to run this suite for real.'
        );
    }

    Embeddings::fake();
});

function createSyncedMariaDbProduct(string $name = 'Bluetooth Speaker', string $sku = 'SPK-001'): Product
{
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    $product = Product::create([
        'name' => $name,
        'sku' => $sku,
        'price' => 49.99,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
    ]);

    $product->rag()->sync();

    return $product;
}

it('accepts the real MariaDB 11.7+ connection instead of throwing UnsupportedVectorBackend', function () {
    expect(fn () => VectorBackendCapability::ensureSupported('mariadb_acceptance'))->not->toThrow(Exception::class);
});

it('round-trips a real embedding vector through the AsVector cast on a genuine vector column', function () {
    $product = createSyncedMariaDbProduct();
    $product->rag()->embed();

    $chunk = RagChunk::query()
        ->whereHas('document', fn ($query) => $query->where('model_id', $product->id))
        ->firstOrFail();

    expect($chunk->embedding)->toBeArray()->toHaveCount(8);
    expect($chunk->embedding[0])->toBeFloat();

    // Force a fresh read from the database (not the in-memory value the
    // write above already holds) to prove the round-trip, not just the cast
    // going one direction.
    $reloaded = RagChunk::query()->findOrFail($chunk->id);
    expect($reloaded->embedding)->toEqual($chunk->embedding);
});

it('records embedding provenance on the chunk when embed() writes a real vector', function () {
    $product = createSyncedMariaDbProduct();
    $product->rag()->embed();

    $chunk = RagChunk::query()
        ->whereHas('document', fn ($query) => $query->where('model_id', $product->id))
        ->firstOrFail();

    expect($chunk->embedding_provider)->toBe(config('ai.default_for_embeddings'));
    expect($chunk->embedding_model)->toBe(config('eloquent-rag.embedding.model'));
    expect($chunk->embedding_dimensions)->toBe((int) config('eloquent-rag.embedding.dimensions'));
    expect($chunk->embedding_hash)->toMatch('/^[a-f0-9]{64}$/');
});

it('resolves a null embedding.provider config to the real laravel/ai default for provenance instead of storing null', function () {
    // eloquent-rag.embedding.provider is null by default, meaning "defer to
    // laravel/ai's own default provider" — that raw null still reaches
    // Embeddings::generate() unchanged, but embedding_provider must record
    // the actual resolved provider so provenance is never a silent null.
    expect(config('eloquent-rag.embedding.provider'))->toBeNull();
    config(['ai.default_for_embeddings' => 'bedrock']);

    $product = createSyncedMariaDbProduct();
    $product->rag()->embed();

    $chunk = RagChunk::query()
        ->whereHas('document', fn ($query) => $query->where('model_id', $product->id))
        ->firstOrFail();

    expect($chunk->embedding_provider)->toBe('bedrock');
});

it('regenerates chunk embeddings via embed() after an embedding model change invalidates them', function () {
    $product = createSyncedMariaDbProduct();
    $product->rag()->embed();

    $chunk = RagChunk::query()
        ->whereHas('document', fn ($query) => $query->where('model_id', $product->id))
        ->firstOrFail();

    expect($chunk->embedding)->not->toBeNull();
    $originalEmbeddingHash = $chunk->embedding_hash;

    config(['eloquent-rag.embedding.model' => 'text-embedding-3-large']);
    $product->fresh(['category', 'brand', 'features'])->rag()->sync();

    expect($chunk->fresh()->embedding)->toBeNull();
    expect($chunk->fresh()->embedding_provider)->toBeNull();
    expect($chunk->fresh()->embedding_model)->toBeNull();
    expect($chunk->fresh()->embedding_dimensions)->toBeNull();
    expect($chunk->fresh()->embedding_hash)->toBeNull();

    $product->rag()->embed();

    expect($chunk->fresh()->embedding)->toBeArray()->toHaveCount(8);
    expect($chunk->fresh()->embedding_model)->toBe('text-embedding-3-large');
    expect($chunk->fresh()->embedding_hash)->not->toBe($originalEmbeddingHash);
    Embeddings::assertGenerated(fn ($prompt): bool => $prompt->model === 'text-embedding-3-large');
});

it('invalidates and regenerates a chunk embedding via sync() -> embed() when the underlying content genuinely changes', function () {
    // Distinct from the embedding-model-change test above: here nothing
    // about the embedding config changes — sync() must detect the
    // content_hash drift caused by a real content edit on its own, and
    // embed() must then pick the now-nulled chunk back up against the real
    // vector column.
    $product = createSyncedMariaDbProduct('Bluetooth Speaker', 'SPK-001');
    $product->rag()->embed();

    $chunk = RagChunk::query()
        ->whereHas('document', fn ($query) => $query->where('model_id', $product->id))
        ->firstOrFail();
    $originalEmbedding = $chunk->embedding;
    $originalHash = $chunk->content_hash;
    $originalEmbeddingHash = $chunk->embedding_hash;

    expect($originalEmbedding)->not->toBeNull();

    $product->update(['name' => 'Bluetooth Speaker Pro']);
    $product->fresh(['category', 'brand', 'features'])->rag()->sync();

    expect($chunk->fresh()->content_hash)->not->toBe($originalHash);
    expect($chunk->fresh()->embedding)->toBeNull();
    expect($chunk->fresh()->embedding_hash)->toBeNull();

    $product->rag()->embed();

    expect($chunk->fresh()->embedding)->toBeArray()->toHaveCount(8);
    expect($chunk->fresh()->embedding)->not->toEqual($originalEmbedding);
    // Same provider/model/dimensions as before, but a different embedding_hash
    // — it's keyed to content_hash too, which genuinely changed here.
    expect($chunk->fresh()->embedding_hash)->not->toBe($originalEmbeddingHash);
});

it('drops a stale embedding write instead of persisting it when a concurrent sync() reconciles the chunk mid-embed()', function () {
    // Regression test for issue #29: embed() re-derives chunk text (via its
    // own render + chunk) and only writes back to the exact rows it read as
    // pending. If a concurrent sync() reconciles this same chunk in between
    // — changing its content_hash and nulling its embedding, per
    // reconcileChunks() — an unconditional write here would pair the new
    // content_hash with an embedding computed from the old text, and
    // whereNull('embedding') would never surface it for re-embedding again.
    $product = createSyncedMariaDbProduct('Bluetooth Speaker', 'SPK-001');

    $chunk = RagChunk::query()
        ->whereHas('document', fn ($query) => $query->where('model_id', $product->id))
        ->firstOrFail();

    $raced = false;

    RagChunk::retrieved(function (RagChunk $retrieved) use ($chunk, $product, &$raced): void {
        if ($raced || $retrieved->id !== $chunk->id) {
            return;
        }

        // Only fire once — sync() below retrieves this same chunk again
        // while reconciling it, which must not re-enter this callback.
        $raced = true;

        // Simulate a concurrent sync() (another worker, dependency
        // fan-out) landing between embed()'s read of $chunk and its write
        // back, having changed the model's rendered content in the
        // meantime.
        $product->update(['name' => 'Bluetooth Speaker Pro']);
        $product->fresh(['category', 'brand', 'features'])->rag()->sync();
    });

    $product->rag()->embed();

    RagChunk::flushEventListeners();

    // The write embed() attempted for the old content must have been
    // skipped: the chunk's content_hash moved on before the write landed.
    expect($chunk->fresh()->embedding)->toBeNull();

    // A subsequent embed() call picks it up correctly, against the now-
    // current content.
    $product->rag()->embed();
    expect($chunk->fresh()->embedding)->toBeArray()->toHaveCount(8);
});

it('does not flip the document to synced against a stale snapshot when a concurrent sync() reconciles it mid-embed() (issue #41)', function () {
    // Regression test for issue #41: embed()'s final "no NULL embeddings
    // left" transition used to be a plain check-then-act
    // ($document->chunks()->whereNull(...)->doesntExist() followed by a
    // separate update()), with no tie back to the document snapshot this
    // embed() call actually started from. A concurrent sync() landing in
    // the gap between the two statements — reconciling a chunk's content
    // and re-nulling its embedding — was invisible to the check, letting
    // an earlier "all NULL-free" read paper over a document that had
    // already moved on underneath it.
    $product = createSyncedMariaDbProduct('Bluetooth Speaker', 'SPK-001');

    $raced = false;

    RagDocument::retrieved(function (RagDocument $retrieved) use ($product, &$raced): void {
        if ($raced || $retrieved->model_id != $product->id) {
            return;
        }

        // Only fire once — the concurrent sync() dispatched below fetches
        // this same document again while reconciling it, which must not
        // re-enter this callback.
        $raced = true;

        // Simulate a concurrent sync() (another worker, dependency
        // fan-out) landing right after embed() reads the document but
        // before it finishes generating/writing the embedding — changing
        // the rendered content and re-nulling the chunk's embedding
        // underneath the in-flight embed() call.
        $product->update(['name' => 'Bluetooth Speaker Pro']);
        $product->fresh(['category', 'brand', 'features'])->rag()->sync();
    });

    $product->rag()->embed();

    RagDocument::flushEventListeners();

    $document = RagDocument::query()->where('model_id', $product->id)->firstOrFail();

    // embed() re-derives chunk text from the model on every call, so the
    // chunk it picked up got embedded against the *current* (post-race)
    // content, per the per-chunk content_hash gate (issue #29's fix) — the
    // embedding itself is genuinely complete.
    expect($document->chunks()->whereNull('embedding')->doesntExist())->toBeTrue();

    // But the document-level status must NOT have flipped to 'synced':
    // this embed() call started from a content_hash/configuration_hash
    // snapshot the concurrent sync() moved past, so the transition is
    // correctly skipped instead of papering over a stale read — the
    // 'pending' status the concurrent sync() itself set is left intact.
    expect($document->status)->toBe('pending');

    // A subsequent embed() call re-reads the now-current hashes and
    // completes the transition normally.
    $product->fresh(['category', 'brand', 'features'])->rag()->embed();
    expect($document->fresh()->status)->toBe('synced');
});

it('performs a real sync -> embed -> search cycle against the vector column without error', function () {
    $match = createSyncedMariaDbProduct('Bluetooth Speaker', 'SPK-001');
    $match->rag()->embed();

    $other = createSyncedMariaDbProduct('Desk Lamp', 'LMP-002');
    $other->rag()->embed();

    $results = Product::searchRag('bluetooth speaker', 5);

    // Proves the real orderByVectorDistance()/join/hydration chain executes
    // against MariaDB's vec_distance_cosine() without a SQL error and
    // returns actual hydrated Product models — not that the ranking is
    // semantically correct (Embeddings::fake() carries no real meaning, so
    // relevance ordering isn't something this test can honestly claim).
    expect($results)->toBeInstanceOf(EloquentCollection::class);
    expect($results)->not->toBeEmpty();
    expect($results->first())->toBeInstanceOf(Product::class);
    expect($results->pluck('id')->all())->toEqualCanonicalizing([$match->id, $other->id]);
});

it('ranks documents by their single best chunk, not by how many close chunks one document has', function () {
    // Regression test for the bug fixed alongside this test: ranking used
    // to be decided by over-fetching a fixed multiple of chunk-level rows
    // and deduping down to documents, which could fill the entire
    // over-fetch window with one document's near-duplicate chunks and
    // silently drop a genuinely better-ranked second document. This uses
    // exact, un-roundable vector geometry against the real
    // vec_distance_cosine() function — identical vectors are always
    // cosine distance 0, orthogonal vectors are always exactly 1, and
    // opposite vectors are always exactly 2 — so the expected order below
    // is not an approximation.
    $vectorDimensions = (int) config('eloquent-rag.embedding.dimensions');
    $unitVector = fn (int $onIndex, float $value = 1.0): array => array_replace(
        array_fill(0, $vectorDimensions, 0.0),
        [$onIndex => $value],
    );

    $bestMatch = createSyncedMariaDbProduct('Best Match', 'DOC-A');
    $secondBest = createSyncedMariaDbProduct('Second Best', 'DOC-B');
    $worstMatch = createSyncedMariaDbProduct('Worst Match', 'DOC-C');

    $documentIdFor = fn (Product $product): int => RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->value('id');

    // Document A: 8 chunks identical to the query vector (distance 0
    // each) — many near-duplicate top chunks belonging to one document.
    foreach (range(0, 7) as $offset) {
        RagChunk::create([
            'document_id' => $documentIdFor($bestMatch),
            'chunk_index' => 100 + $offset,
            'content_hash' => str_repeat('a', 64),
            'embedding' => $unitVector(0),
        ]);
    }

    // Document B: a single chunk orthogonal to the query (distance 1) —
    // the genuine second-best document.
    RagChunk::create([
        'document_id' => $documentIdFor($secondBest),
        'chunk_index' => 100,
        'content_hash' => str_repeat('b', 64),
        'embedding' => $unitVector(1),
    ]);

    // Document C: a single chunk pointing the opposite way (distance 2).
    RagChunk::create([
        'document_id' => $documentIdFor($worstMatch),
        'chunk_index' => 100,
        'content_hash' => str_repeat('c', 64),
        'embedding' => $unitVector(0, -1.0),
    ]);

    Embeddings::fake([[$unitVector(0)]]);

    $results = Product::searchRag('anything', 2);

    expect($results->pluck('id')->all())->toBe([$bestMatch->id, $secondBest->id]);
});

it('excludes a document whose best chunk falls below the minSimilarity floor, using exact vector geometry', function () {
    // Same exact-geometry technique as the ranking regression test above:
    // identical vectors are always cosine similarity 1.0, orthogonal
    // vectors are always exactly 0.0 — not an approximation.
    $vectorDimensions = (int) config('eloquent-rag.embedding.dimensions');
    $unitVector = fn (int $onIndex, float $value = 1.0): array => array_replace(
        array_fill(0, $vectorDimensions, 0.0),
        [$onIndex => $value],
    );

    $match = createSyncedMariaDbProduct('Best Match', 'DOC-A');
    $tooFar = createSyncedMariaDbProduct('Too Far', 'DOC-B');

    $documentIdFor = fn (Product $product): int => RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->value('id');

    // Identical to the query vector: cosine similarity exactly 1.0.
    RagChunk::create([
        'document_id' => $documentIdFor($match),
        'chunk_index' => 100,
        'content_hash' => str_repeat('a', 64),
        'embedding' => $unitVector(0),
    ]);

    // Orthogonal to the query vector: cosine similarity exactly 0.0 — below
    // the 0.5 floor below, so this document must be excluded entirely, not
    // merely ranked last.
    RagChunk::create([
        'document_id' => $documentIdFor($tooFar),
        'chunk_index' => 100,
        'content_hash' => str_repeat('b', 64),
        'embedding' => $unitVector(1),
    ]);

    Embeddings::fake([[$unitVector(0)]]);

    $results = Product::searchRag('anything', 5, minSimilarity: 0.5);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('treats minSimilarity as an inclusive floor at the exact boundary', function () {
    $vectorDimensions = (int) config('eloquent-rag.embedding.dimensions');
    $unitVector = fn (int $onIndex, float $value = 1.0): array => array_replace(
        array_fill(0, $vectorDimensions, 0.0),
        [$onIndex => $value],
    );

    $product = createSyncedMariaDbProduct('Orthogonal Match', 'DOC-A');

    $documentId = RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->value('id');

    // Orthogonal to the query vector: cosine similarity exactly 0.0.
    RagChunk::create([
        'document_id' => $documentId,
        'chunk_index' => 100,
        'content_hash' => str_repeat('a', 64),
        'embedding' => $unitVector(1),
    ]);

    Embeddings::fake([[$unitVector(0)]]);

    $results = Product::searchRag('anything', 5, minSimilarity: 0.0);

    expect($results->pluck('id')->all())->toBe([$product->id]);
});

it('rag:doctor reads the real declared vector column dimension and matches config', function () {
    createSyncedMariaDbProduct();

    Artisan::call('rag:doctor');
    $output = Artisan::output();

    expect($output)->toContain('[PASS] rag_chunks.embedding is declared as vector(8), matching');
});

it('does not have a vector index on rag_chunks.embedding, since MariaDB requires NOT NULL and the column is deliberately nullable (ADR-0008)', function () {
    $indexes = DB::select('show index from rag_chunks where Key_name = ?', ['rag_chunks_embedding_vector_index']);

    expect($indexes)->toBeEmpty();
});

it('rag:doctor warns that vector search runs a full table scan without an indexed NOT NULL column on MariaDB', function () {
    createSyncedMariaDbProduct();

    Artisan::call('rag:doctor');
    $output = Artisan::output();

    expect($output)->toContain('[WARN] No vector index on rag_chunks.embedding')
        ->toContain('NOT NULL');
});

it('cascades document, chunk, and dependency deletion against the real vector column when the model is deleted', function () {
    // Closes the lifecycle loop this suite otherwise stops short of: every
    // earlier test here proves creation/embedding/search against a genuine
    // vector column, but none of them prove that deleting the owning model
    // actually clears its row out of that same real column afterward.
    // config('queue.default') is 'sync' in this test case, so
    // ForgetRagDocument (dispatched after-commit per ADR-0006) runs inline
    // and this assertion needs no queue draining.
    $product = createSyncedMariaDbProduct();
    $product->rag()->embed();

    $document = RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->firstOrFail();
    $documentId = $document->id;

    expect(RagChunk::where('document_id', $documentId)->count())->toBeGreaterThan(0);
    expect(RagDependency::where('document_id', $documentId)->count())->toBeGreaterThan(0);

    $product->delete();

    expect(RagDocument::find($documentId))->toBeNull();
    expect(RagChunk::where('document_id', $documentId)->count())->toBe(0);
    expect(RagDependency::where('document_id', $documentId)->count())->toBe(0);
});
