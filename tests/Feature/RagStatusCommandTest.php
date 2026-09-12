<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;

it('reports correct document/chunk/dependency counts after creating products', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);
    Product::create([
        'name' => 'Headphones', 'sku' => 'SPK-002', 'price' => 29.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    $this->artisan('rag:status')
        ->expectsOutputToContain('Documents: 2')
        ->expectsOutputToContain('Dependencies: 4')
        ->assertExitCode(0);
});
