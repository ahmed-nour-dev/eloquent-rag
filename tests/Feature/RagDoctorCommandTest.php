<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;

/**
 * This package's own test suite runs on SQLite — a real, genuine example
 * of an ADR-0003-unsupported backend. Asserting rag:doctor reports FAIL
 * and a non-zero exit code here is a real test, not a mock: this is
 * exactly the plan's Phase 4 exit criterion ("a user on ... MariaDB 11.7+
 * gets a clear, actionable rag:doctor failure ... not an obscure SQL
 * error four queue jobs deep"), just demonstrated against SQLite instead
 * of a below-floor MariaDB — both are genuinely unsupported connections.
 */
it('fails with a non-zero exit code and a clear message on the unsupported SQLite connection', function () {
    $this->artisan('rag:doctor')
        ->expectsOutputToContain('[FAIL]')
        ->assertExitCode(1);
});

it('reports failed documents with their last_error as a WARN, not silently', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->update(['status' => 'failed', 'last_error' => 'boom: something broke']);

    $this->artisan('rag:doctor')
        ->expectsOutputToContain('1 document(s) have status')
        ->expectsOutputToContain('boom: something broke');
});
