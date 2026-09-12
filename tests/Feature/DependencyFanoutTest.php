<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\Bus;

/**
 * @return list<Product>
 */
function createProductsUnderSharedCategory(int $count, Category $category, Brand $brand): array
{
    $products = [];

    for ($i = 0; $i < $count; $i++) {
        $products[] = Product::create([
            'name' => "Speaker {$i}",
            'sku' => "SPK-{$i}",
            'price' => 49.99,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ]);
    }

    return $products;
}

function contentHashFor(Product $product): ?string
{
    return RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->value('content_hash');
}

it('re-syncs every dependent product document when a shared Category is renamed', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $products = createProductsUnderSharedCategory(3, $category, $brand);

    $before = collect($products)->mapWithKeys(fn (Product $product) => [$product->id => contentHashFor($product)]);

    $category->update(['name' => 'Consumer Electronics']);

    foreach ($products as $product) {
        expect(contentHashFor($product))->not->toBe($before[$product->id]);
    }
});

it('coalesces rapid repeated saves on the same dependency into a single fan-out dispatch', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    createProductsUnderSharedCategory(3, $category, $brand);

    // Fake the bus only now: Product::create() above already dispatched its
    // own self-sync jobs, which aren't part of what this test measures.
    Bus::fake();

    for ($i = 0; $i < 10; $i++) {
        $category->update(['name' => "Consumer Electronics {$i}"]);
    }

    Bus::assertDispatchedTimes(SyncRagDocument::class, 1);
});

it('does not fan out to a document that does not depend on the saved model', function () {
    $categoryA = Category::create(['name' => 'Electronics']);
    $categoryB = Category::create(['name' => 'Furniture']);
    $brand = Brand::create(['name' => 'Acme']);

    $productA = createProductsUnderSharedCategory(1, $categoryA, $brand)[0];
    $productB = createProductsUnderSharedCategory(1, $categoryB, $brand)[0];

    $beforeB = contentHashFor($productB);

    $categoryA->update(['name' => 'Consumer Electronics']);

    expect(contentHashFor($productB))->toBe($beforeB);
});
