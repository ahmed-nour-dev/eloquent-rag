<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\BrokenProduct;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;

/**
 * Issue #54: SyncRagDocument::handle() used to let one pair's sync()
 * exception propagate out of the loop, aborting every other pair in the
 * same batch (up to config('eloquent-rag.queue.batch_size')). These tests
 * put a genuinely-throwing pair (BrokenProduct — same table as Product,
 * but a mistyped relation() path, so sync() always throws
 * InvalidRelationPath) next to a healthy pair in one batch.
 */
function documentForBatchIsolationPair(string $modelType, int|string $modelId): ?RagDocument
{
    return RagDocument::query()
        ->where('model_type', $modelType)
        ->where('model_id', $modelId)
        ->first();
}

/**
 * Simulates a document that previously synced fine before its relation
 * path was renamed/mistyped in application code (the exact scenario the
 * issue describes), rather than one that never synced at all — so
 * recordFailure() has a real row to update.
 */
function seedDocumentFor(BrokenProduct $model): RagDocument
{
    return RagDocument::create([
        'model_type' => BrokenProduct::class,
        'model_id' => $model->id,
        'content_hash' => str_repeat('a', 64),
        'configuration_hash' => str_repeat('b', 64),
        'status' => 'synced',
        'synced_at' => now(),
    ]);
}

it('keeps processing the rest of the batch after one pair throws, and records the failure on that pair alone', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    $broken = Product::create([
        'name' => 'Broken Speaker', 'sku' => 'SPK-BAD', 'price' => 19.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);
    $healthy = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    seedDocumentFor(BrokenProduct::find($broken->id));
    $healthyHashBefore = documentForBatchIsolationPair(Product::class, $healthy->id)->content_hash;

    $category->update(['name' => 'Consumer Electronics']);

    $batch = [
        ['model_type' => BrokenProduct::class, 'model_id' => $broken->id],
        ['model_type' => Product::class, 'model_id' => $healthy->id],
    ];

    (new SyncRagDocument($batch))->handle();

    // The healthy pair, listed after the throwing one, was still processed
    // — the batch wasn't aborted.
    $healthyDoc = documentForBatchIsolationPair(Product::class, $healthy->id);
    expect($healthyDoc->content_hash)->not->toBe($healthyHashBefore);
    expect($healthyDoc->status)->not->toBe('failed');

    // The throwing pair's own document row is marked failed instead of the
    // exception propagating out of handle().
    $brokenDoc = documentForBatchIsolationPair(BrokenProduct::class, $broken->id);
    expect($brokenDoc->status)->toBe('failed');
    expect($brokenDoc->last_error)->toContain('categroy');
});

it('does not throw out of handle() when every pair in the batch fails', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    $broken = Product::create([
        'name' => 'Broken Speaker', 'sku' => 'SPK-BAD', 'price' => 19.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    seedDocumentFor(BrokenProduct::find($broken->id));

    $batch = [
        ['model_type' => BrokenProduct::class, 'model_id' => $broken->id],
        ['model_type' => BrokenProduct::class, 'model_id' => $broken->id],
    ];

    (new SyncRagDocument($batch))->handle();

    expect(documentForBatchIsolationPair(BrokenProduct::class, $broken->id)->status)->toBe('failed');
});

it('skips a pair whose model was concurrently deleted without affecting the rest of the batch', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    $healthy = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    $healthyHashBefore = documentForBatchIsolationPair(Product::class, $healthy->id)->content_hash;

    $category->update(['name' => 'Consumer Electronics']);

    $batch = [
        ['model_type' => Product::class, 'model_id' => 999_999], // never existed
        ['model_type' => Product::class, 'model_id' => $healthy->id],
    ];

    (new SyncRagDocument($batch))->handle();

    $healthyDoc = documentForBatchIsolationPair(Product::class, $healthy->id);
    expect($healthyDoc->content_hash)->not->toBe($healthyHashBefore);
});
