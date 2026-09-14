<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagChunk;
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

it('regenerates chunk embeddings via embed() after an embedding model change invalidates them', function () {
    $product = createSyncedMariaDbProduct();
    $product->rag()->embed();

    $chunk = RagChunk::query()
        ->whereHas('document', fn ($query) => $query->where('model_id', $product->id))
        ->firstOrFail();

    expect($chunk->embedding)->not->toBeNull();

    config(['eloquent-rag.embedding.model' => 'text-embedding-3-large']);
    $product->fresh(['category', 'brand', 'features'])->rag()->sync();

    expect($chunk->fresh()->embedding)->toBeNull();

    $product->rag()->embed();

    expect($chunk->fresh()->embedding)->toBeArray()->toHaveCount(8);
    Embeddings::assertGenerated(fn ($prompt): bool => $prompt->model === 'text-embedding-3-large');
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
