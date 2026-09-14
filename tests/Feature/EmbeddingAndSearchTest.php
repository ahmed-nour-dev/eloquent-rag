<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Ahmednour\EloquentRag\Rag;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Laravel\Ai\Embeddings;

/**
 * This package's test suite runs on SQLite, a real ADR-0003-unsupported
 * backend. RagSynchronizer::embed() and RagSearch::search() both call
 * VectorBackendCapability::ensureSupported() FIRST — before any
 * embedding API call or vector query — so these tests exercise the real
 * rejection path (no mocking) and confirm the rejection genuinely happens
 * before any embeddings work is attempted, matching the build plan's
 * "thrown at config/boot time, not mid-queue" requirement.
 *
 * What this file does NOT prove: that embed()/search() produce correct SQL
 * or correct results against a real MariaDB 11.7+/pgvector connection.
 * That requires the real backend this sandbox does not have — see the
 * Phase 3 report for what's still needed before the plan's stated exit
 * criteria ("end-to-end on both MariaDB 11.7+ and PostgreSQL+pgvector, in
 * CI") is actually met.
 */
function createSyncedProduct(): Product
{
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    return Product::create([
        'name' => 'Speaker',
        'sku' => 'SPK-001',
        'price' => 49.99,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
    ]);
}

it('rejects embed() on the unsupported SQLite connection before generating any embeddings', function () {
    Embeddings::fake();

    $product = createSyncedProduct();

    expect(fn () => $product->rag()->embed())->toThrow(UnsupportedVectorBackend::class);

    Embeddings::assertNothingGenerated();
});

it('rejects HasRag::searchRag() on the unsupported SQLite connection before generating any embeddings', function () {
    Embeddings::fake();

    createSyncedProduct();

    expect(fn () => Product::searchRag('a bluetooth speaker'))->toThrow(UnsupportedVectorBackend::class);

    Embeddings::assertNothingGenerated();
});

it('rejects Rag::search() the same way as the instance-bound entry point', function () {
    Embeddings::fake();

    createSyncedProduct();

    expect(fn () => Rag::search(Product::class, 'a bluetooth speaker'))->toThrow(UnsupportedVectorBackend::class);

    Embeddings::assertNothingGenerated();
});

it('still applies a valid minSimilarity and reaches the unsupported-backend rejection', function () {
    Embeddings::fake();

    createSyncedProduct();

    expect(fn () => Product::searchRag('a bluetooth speaker', minSimilarity: 0.7))
        ->toThrow(UnsupportedVectorBackend::class);

    Embeddings::assertNothingGenerated();
});

it('rejects an out-of-range minSimilarity before checking backend support or generating embeddings', function (float $minSimilarity) {
    Embeddings::fake();

    createSyncedProduct();

    expect(fn () => Product::searchRag('a bluetooth speaker', minSimilarity: $minSimilarity))
        ->toThrow(InvalidArgumentException::class);

    Embeddings::assertNothingGenerated();
})->with([
    'below 0.0' => [-0.01],
    'above 1.0' => [1.01],
]);

/**
 * Isolated, honest check of laravel/ai's real API shape using its official
 * fake — NOT routed through RagSynchronizer/RagSearch (which correctly
 * refuse to reach this code on an unsupported connection, as proven
 * above). This only confirms our understanding of Embeddings::for(...)
 * ->dimensions(...)->generate(...) and its fake are correct, decoupled
 * from this package's own gating logic.
 */
it('confirms the laravel/ai embeddings call shape this package relies on, via its official fake', function () {
    Embeddings::fake();

    $response = Embeddings::for(['a bluetooth speaker'])
        ->dimensions(1536)
        ->generate(null, 'text-embedding-3-small');

    expect($response->embeddings)->toHaveCount(1);
    expect($response->embeddings[0])->toHaveCount(1536);

    Embeddings::assertGenerated(fn ($prompt): bool => $prompt->model === 'text-embedding-3-small');
});
