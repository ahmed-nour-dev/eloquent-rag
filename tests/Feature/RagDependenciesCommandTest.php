<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;

it('lists a document\'s declared dependency rows', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    // Each expectsOutputToContain() check must match a genuinely distinct
    // output line — Testbench matches substrings against individual
    // doWrite() calls, and a generic/overlapping substring (e.g. a bare
    // "1", which both ids happen to be in a fresh test database) can be
    // greedily consumed by the wrong expectation, starving a later one of
    // the call that would have satisfied it. "Category #1" / "Brand #1"
    // are each unique to their own line.
    $this->artisan('rag:dependencies', ['model' => Product::class, '--id' => $product->id])
        ->expectsOutputToContain("Category #{$category->id}")
        ->expectsOutputToContain("Brand #{$brand->id}")
        ->assertExitCode(0);
});

it('reports failure when no document exists for the given model/id', function () {
    $this->artisan('rag:dependencies', ['model' => Product::class, '--id' => 999])
        ->expectsOutputToContain('No document found')
        ->assertExitCode(1);
});
