<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Directly exercises the documented ADR-0006 gap: a raw delete bypasses
 * every Eloquent event, so HasRag's `deleted` hook never fires and the
 * document is never forgotten automatically. rag:prune is the operational
 * catch-up.
 */
it('removes a document whose underlying model was deleted via a raw query bypassing Eloquent events', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $survivor = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);
    $orphaned = Product::create([
        'name' => 'Headphones', 'sku' => 'SPK-002', 'price' => 29.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    $orphanedId = $orphaned->id;

    // Bypasses Eloquent entirely — HasRag's deleted() hook never fires.
    DB::table('products')->where('id', $orphanedId)->delete();

    expect(
        RagDocument::query()->where('model_type', Product::class)->where('model_id', $orphanedId)->exists()
    )->toBeTrue();

    $this->artisan('rag:prune')
        ->expectsOutputToContain('Pruned: 1')
        ->assertExitCode(0);

    expect(
        RagDocument::query()->where('model_type', Product::class)->where('model_id', $orphanedId)->exists()
    )->toBeFalse();

    expect(
        RagDocument::query()->where('model_type', Product::class)->where('model_id', $survivor->id)->exists()
    )->toBeTrue();
});
