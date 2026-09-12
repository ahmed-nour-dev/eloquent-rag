<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Ahmednour\EloquentRag\Tests\Integration\PostgresAcceptanceTestCase;
use Ahmednour\EloquentRag\VectorBackendCapability;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Artisan;
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

it('rag:doctor reads the real declared vector column dimension and matches config', function () {
    createSyncedPostgresProduct();

    Artisan::call('rag:doctor');
    $output = Artisan::output();

    expect($output)->toContain('[PASS] rag_chunks.embedding is declared as vector(8), matching');
});
