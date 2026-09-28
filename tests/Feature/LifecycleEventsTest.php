<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Events\RagDocumentEmbedded;
use Ahmednour\EloquentRag\Events\RagDocumentSynced;
use Ahmednour\EloquentRag\Events\RagEmbeddingFailed;
use Ahmednour\EloquentRag\Events\RagSyncFailed;
use Ahmednour\EloquentRag\Exceptions\InvalidRelationPath;
use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\BrokenProduct;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Embeddings;

/**
 * Issue #73: sync()/embed() dispatch Laravel events so apps can observe
 * the lifecycle (logging, Horizon tags, Pulse) without polling
 * rag:status or rag_documents.status. Only the package's own events are
 * faked, so Eloquent's model events — which drive the lifecycle sync —
 * keep firing for real.
 */
function createEventProduct(string $name = 'Speaker'): Product
{
    return Product::create([
        'name' => $name,
        'sku' => 'SKU-'.$name,
        'price' => 10,
        'category_id' => Category::firstOrCreate(['name' => 'Electronics'])->id,
        'brand_id' => Brand::firstOrCreate(['name' => 'Acme'])->id,
    ]);
}

beforeEach(function () {
    Event::fake([RagDocumentSynced::class, RagDocumentEmbedded::class, RagSyncFailed::class, RagEmbeddingFailed::class]);
});

it('fires RagDocumentSynced when the lifecycle sync writes a document', function () {
    $product = createEventProduct();

    Event::assertDispatchedTimes(RagDocumentSynced::class, 1);
    Event::assertDispatched(RagDocumentSynced::class, fn (RagDocumentSynced $event): bool => $event->model->is($product)
        && $event->document->model_id == $product->id
        && $event->forced === false);
});

it('does not fire RagDocumentSynced when sync() short-circuits on unchanged content', function () {
    $product = createEventProduct();

    expect($product->rag()->sync())->toBeFalse();

    Event::assertDispatchedTimes(RagDocumentSynced::class, 1);
});

it('marks a forced sync on the event', function () {
    $product = createEventProduct();

    expect($product->rag()->sync(force: true))->toBeTrue();

    Event::assertDispatched(RagDocumentSynced::class, fn (RagDocumentSynced $event): bool => $event->forced);
});

it('fires RagSyncFailed and still rethrows when sync() fails', function () {
    $product = createEventProduct();
    $broken = BrokenProduct::query()->findOrFail($product->id);

    expect(fn () => $broken->rag()->sync())->toThrow(InvalidRelationPath::class);

    Event::assertDispatched(RagSyncFailed::class, fn (RagSyncFailed $event): bool => $event->model->is($broken)
        && $event->exception instanceof InvalidRelationPath);
});

it('fires RagDocumentEmbedded with the number of chunks embedded, only when embed() did work', function () {
    config(['eloquent-rag.portable_fallback.enabled' => true, 'eloquent-rag.embedding.dimensions' => 3]);
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => [1.0, 0.0, 0.0], $prompt->inputs));

    $product = createEventProduct();
    $product->rag()->embed();

    Event::assertDispatched(RagDocumentEmbedded::class, fn (RagDocumentEmbedded $event): bool => $event->model->is($product)
        && $event->embeddedChunks === 1
        && $event->document->status === 'synced');

    // Nothing left to embed: no second event.
    $product->rag()->embed();

    Event::assertDispatchedTimes(RagDocumentEmbedded::class, 1);
});

it('fires RagEmbeddingFailed and still rethrows when embed() fails', function () {
    Embeddings::fake();

    $product = createEventProduct();

    expect(fn () => $product->rag()->embed())->toThrow(UnsupportedVectorBackend::class);

    Event::assertDispatched(RagEmbeddingFailed::class, fn (RagEmbeddingFailed $event): bool => $event->model->is($product)
        && $event->exception instanceof UnsupportedVectorBackend);
    Event::assertNotDispatched(RagDocumentEmbedded::class);
});
