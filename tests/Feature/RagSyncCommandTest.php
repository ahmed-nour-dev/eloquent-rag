<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\Bus;

function createTwoProducts(): array
{
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    return [
        Product::create([
            'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
            'category_id' => $category->id, 'brand_id' => $brand->id,
        ]),
        Product::create([
            'name' => 'Headphones', 'sku' => 'SPK-002', 'price' => 29.99,
            'category_id' => $category->id, 'brand_id' => $brand->id,
        ]),
        $category,
    ];
}

function documentFor2(Product $product): ?RagDocument
{
    return RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->first();
}

/**
 * embed() genuinely fails here — SQLite is a real unsupported backend, no
 * mocking. This proves both the failure-recording behavior AND that the
 * command keeps processing the rest of the batch rather than aborting.
 */
it('performs a real structural sync, records the real embed() failure on SQLite, and keeps processing the batch', function () {
    [$productA, $productB] = createTwoProducts();

    $this->artisan('rag:sync', ['model' => Product::class])
        ->expectsOutputToContain('Failed (embed)')
        ->expectsOutputToContain('Synced: 0, Failed: 2')
        ->assertExitCode(1);

    $docA = documentFor2($productA);
    $docB = documentFor2($productB);

    expect($docA->status)->toBe('failed');
    expect($docA->last_error)->not->toBeNull();
    expect($docB->status)->toBe('failed');
    expect($docB->last_error)->not->toBeNull();

    // The structural sync side (chunks/dependencies) genuinely succeeded
    // for both, proving the batch wasn't aborted after the first failure.
    expect($docA->chunks()->count())->toBeGreaterThan(0);
    expect($docB->chunks()->count())->toBeGreaterThan(0);
});

it('targets a single model id with --id', function () {
    [$productA, $productB] = createTwoProducts();

    $this->artisan('rag:sync', ['model' => Product::class, '--id' => $productA->id])
        ->expectsOutputToContain('Synced: 0, Failed: 1');

    expect(documentFor2($productA)->status)->toBe('failed');
});

it('rejects --id without a model argument', function () {
    $this->artisan('rag:sync', ['--id' => 1])
        ->assertExitCode(1);
});

it('rejects --dependency combined with model', function () {
    $this->artisan('rag:sync', ['model' => Product::class, '--dependency' => 'App\\Category:1'])
        ->assertExitCode(1);
});

it('with no model and no --dependency, discovers and processes every distinct model_type already in rag_documents', function () {
    createTwoProducts();

    $this->artisan('rag:sync')
        ->expectsOutputToContain('Synced: 0, Failed: 2')
        ->assertExitCode(1);
});

it('with no model, no --dependency, and no existing documents yet, warns instead of crashing', function () {
    $this->artisan('rag:sync')
        ->expectsOutputToContain('nothing to target')
        ->expectsOutputToContain('Synced: 0, Failed: 0')
        ->assertExitCode(0);
});

it('--dependency mode dispatches fan-out via Rag::invalidate()', function () {
    [$productA, $productB, $category] = createTwoProducts();

    Bus::fake();

    $this->artisan('rag:sync', ['--dependency' => Category::class.':'.$category->id])
        ->expectsOutputToContain('Invalidation dispatched')
        ->assertExitCode(0);

    Bus::assertDispatchedTimes(SyncRagDocument::class, 1);
});
