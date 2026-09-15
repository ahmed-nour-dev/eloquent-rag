<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDependency;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Ahmednour\EloquentRag\Tests\Integration\PostgresAcceptanceTestCase;
use Ahmednour\EloquentRag\VectorBackendCapability;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

/**
 * Real-PostgreSQL+pgvector acceptance suite (Phase 5). Requires the
 * RAG_TEST_PGSQL_* env vars (see .github/workflows/tests.yml's postgres
 * job, which also runs `CREATE EXTENSION IF NOT EXISTS vector;` before
 * tests start); skips cleanly everywhere else, including this sandbox.
 *
 * See MariaDbAcceptanceTest's docblock for the full rationale — same
 * structure, same Embeddings::fake() caveat (semantic ordering isn't
 * provable with or without a real backend; only the query mechanics are).
 */
beforeEach(function () {
    if (! PostgresAcceptanceTestCase::isConfigured()) {
        $this->markTestSkipped(
            'RAG_TEST_PGSQL_HOST not set — no real PostgreSQL+pgvector available. '.
            'Set the RAG_TEST_PGSQL_* env vars (see .github/workflows/tests.yml) to run this suite for real.'
        );
    }

    Embeddings::fake();
});

function createSyncedPostgresProduct(string $name = 'Bluetooth Speaker', string $sku = 'SPK-001'): Product
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

it('accepts the real PostgreSQL+pgvector connection instead of throwing UnsupportedVectorBackend', function () {
    expect(fn () => VectorBackendCapability::ensureSupported('pgsql_acceptance'))->not->toThrow(Exception::class);
});

it('round-trips a real embedding vector through the AsVector cast on a genuine vector column', function () {
    $product = createSyncedPostgresProduct();
    $product->rag()->embed();

    $chunk = RagChunk::query()
        ->whereHas('document', fn ($query) => $query->where('model_id', $product->id))
        ->firstOrFail();

    expect($chunk->embedding)->toBeArray()->toHaveCount(8);
    expect($chunk->embedding[0])->toBeFloat();

    $reloaded = RagChunk::query()->findOrFail($chunk->id);
    expect($reloaded->embedding)->toEqual($chunk->embedding);
});

it('records embedding provenance on the chunk when embed() writes a real vector', function () {
    $product = createSyncedPostgresProduct();
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

    $product = createSyncedPostgresProduct();
    $product->rag()->embed();

    $chunk = RagChunk::query()
        ->whereHas('document', fn ($query) => $query->where('model_id', $product->id))
        ->firstOrFail();

    expect($chunk->embedding_provider)->toBe('bedrock');
});

it('regenerates chunk embeddings via embed() after an embedding model change invalidates them', function () {
    $product = createSyncedPostgresProduct();
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
    $product = createSyncedPostgresProduct('Bluetooth Speaker', 'SPK-001');
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
    $product = createSyncedPostgresProduct('Bluetooth Speaker', 'SPK-001');

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

it('performs a real sync -> embed -> search cycle against the vector column without error', function () {
    $match = createSyncedPostgresProduct('Bluetooth Speaker', 'SPK-001');
    $match->rag()->embed();

    $other = createSyncedPostgresProduct('Desk Lamp', 'LMP-002');
    $other->rag()->embed();

    $results = Product::searchRag('bluetooth speaker', 5);

    // Proves the real orderByVectorDistance()/join/hydration chain executes
    // against pgvector's `<=>` operator without a SQL error and returns
    // actual hydrated Product models — see the module docblock for why
    // semantic ordering isn't something this test can honestly claim.
    expect($results)->toBeInstanceOf(EloquentCollection::class);
    expect($results)->not->toBeEmpty();
    expect($results->first())->toBeInstanceOf(Product::class);
    expect($results->pluck('id')->all())->toEqualCanonicalizing([$match->id, $other->id]);
});

it('ranks documents by their single best chunk, not by how many close chunks one document has', function () {
    // Regression test for the bug fixed alongside this test — see
    // MariaDbAcceptanceTest's copy of this test for the full rationale.
    // Uses exact, un-roundable vector geometry against pgvector's `<=>`
    // operator: identical vectors are always cosine distance 0, orthogonal
    // vectors are always exactly 1, and opposite vectors are always
    // exactly 2.
    $vectorDimensions = (int) config('eloquent-rag.embedding.dimensions');
    $unitVector = fn (int $onIndex, float $value = 1.0): array => array_replace(
        array_fill(0, $vectorDimensions, 0.0),
        [$onIndex => $value],
    );

    $bestMatch = createSyncedPostgresProduct('Best Match', 'DOC-A');
    $secondBest = createSyncedPostgresProduct('Second Best', 'DOC-B');
    $worstMatch = createSyncedPostgresProduct('Worst Match', 'DOC-C');

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

    $match = createSyncedPostgresProduct('Best Match', 'DOC-A');
    $tooFar = createSyncedPostgresProduct('Too Far', 'DOC-B');

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

    $product = createSyncedPostgresProduct('Orthogonal Match', 'DOC-A');

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
    createSyncedPostgresProduct();

    Artisan::call('rag:doctor');
    $output = Artisan::output();

    expect($output)->toContain('[PASS] rag_chunks.embedding is declared as vector(8), matching');
});

it('creates a real HNSW vector index on rag_chunks.embedding matching the cosine distance this package queries with (ADR-0008)', function () {
    $index = DB::selectOne(
        'select indexdef from pg_indexes where tablename = ? and indexname = ?',
        ['rag_chunks', 'rag_chunks_embedding_vector_index'],
    );

    expect($index)->not->toBeNull();

    $definition = strtolower($index->indexdef);
    expect($definition)->toContain('using hnsw');
    expect($definition)->toContain('vector_cosine_ops');
});

it('rag:doctor reports the real vector index status on pgvector', function () {
    createSyncedPostgresProduct();

    Artisan::call('rag:doctor');
    $output = Artisan::output();

    expect($output)->toContain('[PASS] rag_chunks.embedding has a vector index');
});

it('cascades document, chunk, and dependency deletion against the real vector column when the model is deleted', function () {
    // Closes the lifecycle loop this suite otherwise stops short of: every
    // earlier test here proves creation/embedding/search against a genuine
    // vector column, but none of them prove that deleting the owning model
    // actually clears its row out of that same real column afterward.
    // config('queue.default') is 'sync' in this test case, so
    // ForgetRagDocument (dispatched after-commit per ADR-0006) runs inline
    // and this assertion needs no queue draining.
    $product = createSyncedPostgresProduct();
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
