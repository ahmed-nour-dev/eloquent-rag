<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\DB;

it('deletes a document even after the underlying model row is already gone', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);
    $productId = $product->id;

    DB::table('products')->where('id', $productId)->delete();

    expect(Product::find($productId))->toBeNull();
    expect(
        RagDocument::query()->where('model_type', Product::class)->where('model_id', $productId)->exists()
    )->toBeTrue();

    $this->artisan('rag:forget', ['model' => Product::class, '--id' => $productId])
        ->expectsOutputToContain('Deleted document')
        ->assertExitCode(0);

    expect(
        RagDocument::query()->where('model_type', Product::class)->where('model_id', $productId)->exists()
    )->toBeFalse();
});

it('reports failure when no document exists for the given model/id', function () {
    $this->artisan('rag:forget', ['model' => Product::class, '--id' => 999])
        ->expectsOutputToContain('No document found')
        ->assertExitCode(1);
});
