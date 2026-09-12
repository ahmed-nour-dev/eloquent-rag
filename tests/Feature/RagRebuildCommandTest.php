<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;

function documentFor3(Product $product): ?RagDocument
{
    return RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->first();
}

it('bumps version on every rebuild and bypasses the staleness short-circuit even with no source changes', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    expect(documentFor3($product)->version)->toBe(1);

    $this->artisan('rag:rebuild', ['model' => Product::class, '--id' => $product->id]);
    expect(documentFor3($product)->version)->toBe(2);

    $this->artisan('rag:rebuild', ['model' => Product::class, '--id' => $product->id]);
    expect(documentFor3($product)->version)->toBe(3);
});
