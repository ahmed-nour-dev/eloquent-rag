<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Jobs\EmbedRagDocuments;
use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Embeddings;

/**
 * Issue #64: with config('eloquent-rag.embedding.auto') on, the queued
 * lifecycle sync chains an EmbedRagDocuments job for documents it actually
 * changed, so a save is searchable without a manual rag:sync. The suite's
 * queue is the sync driver, so the chained job runs inline; the portable
 * fallback makes embed() genuinely run on SQLite.
 */
function createAutoEmbedProduct(string $name = 'Speaker'): Product
{
    return Product::create([
        'name' => $name,
        'sku' => 'SKU-'.$name,
        'price' => 10,
        'category_id' => Category::firstOrCreate(['name' => 'Electronics'])->id,
        'brand_id' => Brand::firstOrCreate(['name' => 'Acme'])->id,
    ]);
}

function autoEmbedPair(Product $product): array
{
    return ['model_type' => Product::class, 'model_id' => $product->id];
}

beforeEach(function () {
    config(['eloquent-rag.portable_fallback.enabled' => true, 'eloquent-rag.embedding.dimensions' => 3]);
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => [1.0, 0.0, 0.0], $prompt->inputs));
});

it('leaves documents pending after a save when auto-embedding is off (the default)', function () {
    createAutoEmbedProduct();

    expect(RagDocument::query()->value('status'))->toBe('pending');
    expect(RagChunk::query()->whereNotNull('embedding')->count())->toBe(0);
});

it('embeds a saved model automatically when auto-embedding is on', function () {
    config(['eloquent-rag.embedding.auto' => true]);

    $product = createAutoEmbedProduct();

    expect(RagDocument::query()->value('status'))->toBe('synced');
    expect(RagChunk::query()->whereNull('embedding')->count())->toBe(0);
    expect(Product::searchRag('speaker')->pluck('id')->all())->toBe([$product->id]);
});

it('only queues embedding for documents the sync pass actually changed', function () {
    $product = createAutoEmbedProduct();
    config(['eloquent-rag.embedding.auto' => true]);
    Bus::fake([EmbedRagDocuments::class]);

    // Already synced by the create above: sync() short-circuits.
    (new SyncRagDocument([autoEmbedPair($product)]))->handle();
    Bus::assertNotDispatched(EmbedRagDocuments::class);

    Product::query()->whereKey($product->id)->update(['name' => 'Speaker Pro']);

    (new SyncRagDocument([autoEmbedPair($product)]))->handle();
    Bus::assertDispatched(EmbedRagDocuments::class, fn (EmbedRagDocuments $job): bool => $job->pairs === [autoEmbedPair($product)]);
});

it('splits auto-embedding into batches of auto_batch_size', function () {
    config(['eloquent-rag.embedding.auto' => true, 'eloquent-rag.embedding.auto_batch_size' => 2]);
    Bus::fake([EmbedRagDocuments::class]);

    $products = collect(['A', 'B', 'C'])->map(fn (string $name) => createAutoEmbedProduct($name));
    Bus::assertDispatchedTimes(EmbedRagDocuments::class, 3); // one per create

    RagDocument::query()->delete();

    (new SyncRagDocument($products->map(autoEmbedPair(...))->all()))->handle();

    Bus::assertDispatchedTimes(EmbedRagDocuments::class, 5); // + 2 batches (2 + 1)
});

it('records an embedding failure on the document instead of failing the batch', function () {
    config(['eloquent-rag.embedding.auto' => true, 'eloquent-rag.portable_fallback.enabled' => false]);

    createAutoEmbedProduct();

    $document = RagDocument::query()->firstOrFail();

    expect($document->status)->toBe('failed');
    expect($document->last_error)->toContain('no native vector support');
});

it('warns in rag:doctor when synced documents have no embeddings yet', function () {
    createAutoEmbedProduct();

    $this->artisan('rag:doctor')
        ->expectsOutputToContain("1 document(s) are synced but have no embeddings yet (status = 'pending'), so search can't find them. Saving a model only syncs it — run `php artisan rag:sync`");
});
