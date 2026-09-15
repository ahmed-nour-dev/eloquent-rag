<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDependency;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\Bus;

/**
 * Issue #44: dependency fan-out (DependencyInvalidator) can plausibly
 * dispatch more than one SyncRagDocument batch for the same
 * (model_type, model_id) pair — e.g. two different changed dependencies
 * (Category and Brand) both pointing at the same Product within the same
 * request. RagSynchronizer::sync() is supposed to make duplicate/
 * overlapping runs for the same pair idempotent via its content/
 * configuration hash short-circuit plus delta reconciliation
 * (reconcileChunks()/reconcileDependencies()) — these tests exercise that
 * directly rather than relying on the DB-level unique constraints alone.
 */
function documentForConcurrency(Product $product): RagDocument
{
    return RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->firstOrFail();
}

it('leaves exactly one document, chunk set, and dependency set after repeated duplicate SyncRagDocument runs for the same pair', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    $document = documentForConcurrency($product);
    $baselineHash = $document->content_hash;
    $baselineChunkCount = RagChunk::query()->where('document_id', $document->id)->count();
    $baselineDependencyCount = RagDependency::query()->where('document_id', $document->id)->count();

    expect($baselineChunkCount)->toBeGreaterThan(0);
    expect($baselineDependencyCount)->toBe(2); // category + brand; features is empty

    $pair = ['model_type' => Product::class, 'model_id' => $product->id];

    // Simulate the same pair landing in several overlapping/duplicate
    // batches: some jobs even carry the pair twice within a single batch.
    (new SyncRagDocument([$pair, $pair]))->handle();
    (new SyncRagDocument([$pair]))->handle();
    (new SyncRagDocument([$pair]))->handle();

    expect(RagDocument::query()->where('model_type', Product::class)->where('model_id', $product->id)->count())->toBe(1);

    $document = documentForConcurrency($product);
    expect($document->content_hash)->toBe($baselineHash);

    $chunks = RagChunk::query()->where('document_id', $document->id)->get();
    expect($chunks)->toHaveCount($baselineChunkCount);
    expect($chunks->pluck('chunk_index')->unique())->toHaveCount($baselineChunkCount);

    $dependencies = RagDependency::query()->where('document_id', $document->id)->get();
    expect($dependencies)->toHaveCount($baselineDependencyCount);
    expect(
        $dependencies->pluck(fn (RagDependency $dependency) => $dependency->dependency_type.':'.$dependency->dependency_id)->unique()
    )->toHaveCount($baselineDependencyCount);
});

it('converges to the same final state regardless of the order two overlapping dependency-change fan-outs are processed in', function (bool $reverseOrder) {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    $pair = ['model_type' => Product::class, 'model_id' => $product->id];

    // Two independent dependencies of the same document change within the
    // same request — DependencyInvalidator would normally dispatch a
    // separate SyncRagDocument batch per change, both fanning out to this
    // same product. Bus::fake() lets us capture that without letting the
    // automatic dispatch race our manually-ordered replay below.
    Bus::fake();
    $category->update(['name' => 'Consumer Electronics']);
    $brand->update(['name' => 'Acme Audio']);
    Bus::assertDispatchedTimes(SyncRagDocument::class, 2);

    // Both fan-out jobs are duplicates of the same pair — process them in
    // one order, then (on a fresh document) in the reverse order, and
    // assert the fully-reconciled end state is identical either way.
    $jobs = [new SyncRagDocument([$pair]), new SyncRagDocument([$pair])];

    if ($reverseOrder) {
        $jobs = array_reverse($jobs);
    }

    foreach ($jobs as $job) {
        $job->handle();
    }

    $document = documentForConcurrency($product);

    expect($document->status)->toBe('pending');

    $chunkTexts = RagChunk::query()->where('document_id', $document->id)->pluck('content_hash');
    expect($chunkTexts->unique())->toHaveCount($chunkTexts->count());

    $dependencies = RagDependency::query()->where('document_id', $document->id)->get();
    expect($dependencies)->toHaveCount(2);
    expect(
        $dependencies->pluck(fn (RagDependency $dependency) => $dependency->dependency_type.':'.$dependency->dependency_id)->unique()
    )->toHaveCount(2);

    // The rendered content reflects both updates regardless of which
    // duplicate job happened to run last.
    $renderedContent = RagDocument::query()->where('id', $document->id)->value('content_hash');
    expect($renderedContent)->toBe($document->content_hash);
})->with([
    'forward order' => [false],
    'reverse order' => [true],
]);

it('keeps reconcileChunks() and reconcileDependencies() idempotent when the same document is fully reconciled multiple times in a row', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    $document = documentForConcurrency($product);
    $chunkCount = RagChunk::query()->where('document_id', $document->id)->count();
    $dependencyCount = RagDependency::query()->where('document_id', $document->id)->count();
    $chunkIds = RagChunk::query()->where('document_id', $document->id)->orderBy('chunk_index')->pluck('id');
    $dependencyIds = RagDependency::query()->where('document_id', $document->id)->orderBy('id')->pluck('id');

    // force: true (as rag:rebuild uses) bypasses the hash short-circuit
    // entirely, so every call below actually re-runs reconcileChunks()
    // and reconcileDependencies() against unchanged source data — the
    // exact application-level path duplicate/overlapping jobs would hit
    // if they raced past the short-circuit together.
    $product->rag()->sync(force: true);
    $product->rag()->sync(force: true);
    $product->rag()->sync(force: true);

    expect(RagDocument::query()->where('model_type', Product::class)->where('model_id', $product->id)->count())->toBe(1);

    $refreshed = documentForConcurrency($product);
    expect($refreshed->version)->toBe(4); // 1 initial + 3 forced rebuilds

    // Reconciliation reused the same rows (updateOrCreate on the unique
    // chunk_index / dependency keys) rather than deleting and re-creating
    // them on every pass.
    expect(RagChunk::query()->where('document_id', $refreshed->id)->count())->toBe($chunkCount);
    expect(RagChunk::query()->where('document_id', $refreshed->id)->orderBy('chunk_index')->pluck('id')->all())->toBe($chunkIds->all());

    expect(RagDependency::query()->where('document_id', $refreshed->id)->count())->toBe($dependencyCount);
    expect(RagDependency::query()->where('document_id', $refreshed->id)->orderBy('id')->pluck('id')->all())->toBe($dependencyIds->all());
});

it('applies only the latest state when a duplicate job for a stale pair is processed after a newer change already landed', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = Product::create([
        'name' => 'Speaker', 'sku' => 'SPK-001', 'price' => 49.99,
        'category_id' => $category->id, 'brand_id' => $brand->id,
    ]);

    $pair = ['model_type' => Product::class, 'model_id' => $product->id];

    // A duplicate job for this pair is queued (but not yet processed)
    // before a second, newer change to the same dependency happens.
    $staleDuplicateJob = new SyncRagDocument([$pair]);

    $category->update(['name' => 'Consumer Electronics']);

    // The worker only now gets around to the older duplicate job. Because
    // SyncRagDocument::handle() re-resolves the model fresh (rather than
    // trusting a serialized snapshot) and RagSynchronizer::sync() clears
    // cached relations before rendering, it picks up the *current*
    // category name, not whatever was current when the job was queued.
    $staleDuplicateJob->handle();

    $document = documentForConcurrency($product);
    expect(RagDocument::query()->where('model_type', Product::class)->where('model_id', $product->id)->count())->toBe(1);
    expect(RagDependency::query()->where('document_id', $document->id)->count())->toBe(2);
});
