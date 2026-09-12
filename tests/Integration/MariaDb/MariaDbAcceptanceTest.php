<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Ahmednour\EloquentRag\Tests\Integration\MariaDbAcceptanceTestCase;
use Ahmednour\EloquentRag\VectorBackendCapability;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Artisan;
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

it('rag:doctor reads the real declared vector column dimension and matches config', function () {
    createSyncedMariaDbProduct();

    Artisan::call('rag:doctor');
    $output = Artisan::output();

    expect($output)->toContain('[PASS] rag_chunks.embedding is declared as vector(8), matching');
});
