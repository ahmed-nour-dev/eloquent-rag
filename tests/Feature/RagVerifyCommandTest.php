<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Feature;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Issue #68: rag:verify finds the *result* of the two silent gotchas —
 * a pivot change without resyncRag() and a mass update without
 * Rag::invalidate() — by reconciling stored documents against a fresh
 * render.
 */
function createVerifyProduct(string $name = 'Speaker'): Product
{
    return Product::create([
        'name' => $name,
        'sku' => 'SKU-'.$name,
        'price' => 10,
        'category_id' => Category::firstOrCreate(['name' => 'Electronics'])->id,
        'brand_id' => Brand::firstOrCreate(['name' => 'Acme'])->id,
    ]);
}

it('passes when every document matches its model', function () {
    createVerifyProduct();

    $this->artisan('rag:verify')
        ->expectsOutputToContain('Checked: 1, Drifted: 0, Fixed: 0, Orphaned: 0')
        ->assertExitCode(0);
});

it('detects a pivot attach that was never followed by resyncRag()', function () {
    $product = createVerifyProduct();
    $product->features()->attach(Feature::create(['name' => 'Bluetooth'])->id);

    expect($product->rag()->inspect())->toBe(['content', 'dependencies']);

    $this->artisan('rag:verify')
        ->expectsOutputToContain('Drifted: 1')
        ->assertExitCode(1);
});

it('detects a mass update that bypassed Rag::invalidate()', function () {
    $product = createVerifyProduct();
    Category::query()->whereKey($product->getAttribute('category_id'))->update(['name' => 'Audio']);

    expect($product->rag()->inspect())->toBe(['content']);

    $this->artisan('rag:verify', ['model' => Product::class])->assertExitCode(1);
});

it('detects a configuration change', function () {
    $product = createVerifyProduct();
    config(['eloquent-rag.chunk.max_tokens' => 123]);

    expect($product->rag()->inspect())->toBe(['configuration']);
});

it('re-syncs drifted documents with --fix and then passes', function () {
    $product = createVerifyProduct();
    $product->features()->attach(Feature::create(['name' => 'Bluetooth'])->id);

    $this->artisan('rag:verify', ['--fix' => true])
        ->expectsOutputToContain('Drifted: 1, Fixed: 1')
        ->assertExitCode(0);

    expect($product->rag()->inspect())->toBe([]);
    $this->artisan('rag:verify')->assertExitCode(0);
});

it('finds models with no document when a model class is given, and fixes them', function () {
    createVerifyProduct();
    $inserted = createVerifyProduct('Laptop');
    RagDocument::query()->where('model_id', $inserted->id)->delete();

    expect($inserted->rag()->inspect())->toBe(['missing']);

    $this->artisan('rag:verify', ['model' => Product::class])
        ->expectsOutputToContain('Drifted: 1')
        ->assertExitCode(1);

    $this->artisan('rag:verify', ['model' => Product::class, '--fix' => true])->assertExitCode(0);

    expect(RagDocument::query()->where('model_id', $inserted->id)->exists())->toBeTrue();
});

it('reports orphaned documents and points at rag:prune without failing', function () {
    $product = createVerifyProduct();
    DB::table('products')->where('id', $product->id)->delete();

    $this->artisan('rag:verify')
        ->expectsOutputToContain('Orphaned: 1')
        ->assertExitCode(0);
});

it('checks only a random sample with --sample', function () {
    collect(['A', 'B', 'C'])->each(fn (string $name) => createVerifyProduct($name));

    $this->artisan('rag:verify', ['--sample' => 2])
        ->expectsOutputToContain('Checked: 2')
        ->assertExitCode(0);
});

it('rejects an invalid --sample', function () {
    $this->artisan('rag:verify', ['--sample' => 'abc'])->assertExitCode(1);
});
