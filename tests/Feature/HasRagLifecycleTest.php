<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDependency;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Support\Chunker;
use Ahmednour\EloquentRag\Support\RagDocumentBuilder;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Feature;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

function createSpeaker(Category $category, Brand $brand): Product
{
    return Product::create([
        'name' => 'Speaker',
        'sku' => 'SPK-001',
        'price' => 49.99,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
    ]);
}

function documentFor(Product $product): ?RagDocument
{
    return RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->first();
}

it('creates a document with matching chunks and category/brand dependencies on Product::create()', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    $product = createSpeaker($category, $brand);
    $document = documentFor($product);

    expect($document)->not->toBeNull();
    expect($document->status)->toBe('pending');

    $rendered = (new RagDocumentBuilder)->render(
        $product->fresh(['category', 'brand', 'features']),
        $product->toRagDefinition(),
    );
    $expectedChunkCount = count((new Chunker)->chunk($rendered));

    expect($document->chunks()->count())->toBe($expectedChunkCount);

    $dependencyTypes = $document->dependencies()->pluck('dependency_type')->sort()->values()->all();
    expect($dependencyTypes)->toBe([Brand::class, Category::class]);
});

it('leaves dependency rows unchanged after attach() until resyncRag() is called, per ADR-0007', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $feature = Feature::create(['name' => 'Waterproof']);

    $product = createSpeaker($category, $brand);
    $document = documentFor($product);

    expect($document->dependencies()->count())->toBe(2);

    $product->features()->attach($feature->id);

    expect($document->fresh()->dependencies()->count())->toBe(2);

    $product->resyncRag();

    expect($document->fresh()->dependencies()->count())->toBe(3);
    expect($document->dependencies()->where('dependency_type', Feature::class)->value('dependency_id'))
        ->toBe($feature->id);
});

it('resyncRag() reconciles exactly the changed feature dependency, leaving other products untouched', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $waterproof = Feature::create(['name' => 'Waterproof']);
    $bluetooth = Feature::create(['name' => 'Bluetooth']);

    $productA = createSpeaker($category, $brand);
    $productA->features()->attach([$waterproof->id, $bluetooth->id]);
    $productA->resyncRag();

    $productB = createSpeaker($category, $brand);
    $productB->features()->attach([$waterproof->id]);
    $productB->resyncRag();

    $docA = documentFor($productA);
    $docB = documentFor($productB);

    expect($docA->dependencies()->where('dependency_type', Feature::class)->count())->toBe(2);
    expect($docB->dependencies()->where('dependency_type', Feature::class)->count())->toBe(1);

    $productA->features()->detach($bluetooth->id);
    $productA->resyncRag();

    expect(
        $docA->fresh()->dependencies()->where('dependency_type', Feature::class)->pluck('dependency_id')->all()
    )->toBe([$waterproof->id]);

    // productB's rows are untouched by productA's resync.
    expect($docB->fresh()->dependencies()->where('dependency_type', Feature::class)->count())->toBe(1);
});

it('cascades document, chunk, and dependency deletion when the model is deleted', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = createSpeaker($category, $brand);

    $document = documentFor($product);
    expect($document->chunks()->count())->toBeGreaterThan(0);
    expect($document->dependencies()->count())->toBeGreaterThan(0);
    $documentId = $document->id;

    $product->delete();

    expect(RagDocument::find($documentId))->toBeNull();
    expect(RagChunk::where('document_id', $documentId)->count())->toBe(0);
    expect(RagDependency::where('document_id', $documentId)->count())->toBe(0);
});

it('invalidates a chunk embedding when its content changes on resync', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = createSpeaker($category, $brand);

    $document = documentFor($product);
    $chunk = $document->chunks()->orderBy('chunk_index')->first();
    $originalHash = $chunk->content_hash;
    $chunk->update(['embedding' => [0.1, 0.2, 0.3]]);

    $product->name = 'Speaker Pro';
    $product->save();
    $product->fresh(['category', 'brand', 'features'])->rag()->sync();

    $chunk->refresh();

    expect($chunk->content_hash)->not->toBe($originalHash);
    expect($chunk->embedding)->toBeNull();
});

it('leaves an unchanged chunk embedding untouched on resync', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = createSpeaker($category, $brand);

    $document = documentFor($product);
    $chunk = $document->chunks()->orderBy('chunk_index')->first();
    $chunk->update(['embedding' => [0.1, 0.2, 0.3]]);

    // Force a resync (nothing about the rendered content changed) and
    // confirm the untouched chunk keeps its embedding.
    $product->fresh(['category', 'brand', 'features'])->rag()->sync(force: true);

    $chunk->refresh();

    expect($chunk->embedding)->not->toBeNull();
});

it('invalidates every chunk embedding, even unchanged ones, when the embedding model changes', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = createSpeaker($category, $brand);

    $document = documentFor($product);
    $document->chunks->each(fn (RagChunk $chunk) => $chunk->update(['embedding' => [0.1, 0.2, 0.3]]));

    config(['eloquent-rag.embedding.model' => 'text-embedding-3-large']);
    $product->fresh(['category', 'brand', 'features'])->rag()->sync();

    expect($document->fresh()->chunks->pluck('embedding')->filter()->isEmpty())->toBeTrue();
});

it('invalidates every chunk embedding, even unchanged ones, when the embedding dimensions change', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = createSpeaker($category, $brand);

    $document = documentFor($product);
    $document->chunks->each(fn (RagChunk $chunk) => $chunk->update(['embedding' => [0.1, 0.2, 0.3]]));

    config(['eloquent-rag.embedding.dimensions' => 3072]);
    $product->fresh(['category', 'brand', 'features'])->rag()->sync();

    expect($document->fresh()->chunks->pluck('embedding')->filter()->isEmpty())->toBeTrue();
});

it('invalidates every chunk embedding, even unchanged ones, when the embedding provider changes', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = createSpeaker($category, $brand);

    $document = documentFor($product);
    $document->chunks->each(fn (RagChunk $chunk) => $chunk->update(['embedding' => [0.1, 0.2, 0.3]]));

    config(['eloquent-rag.embedding.provider' => 'ollama']);
    $product->fresh(['category', 'brand', 'features'])->rag()->sync();

    expect($document->fresh()->chunks->pluck('embedding')->filter()->isEmpty())->toBeTrue();
});

it('marks the document pending again after an embedding configuration change invalidates its chunks', function () {
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = createSpeaker($category, $brand);

    $document = documentFor($product);
    $document->chunks->each(fn (RagChunk $chunk) => $chunk->update(['embedding' => [0.1, 0.2, 0.3]]));
    $document->update(['status' => 'synced']);

    config(['eloquent-rag.embedding.model' => 'text-embedding-3-large']);
    $product->fresh(['category', 'brand', 'features'])->rag()->sync();

    expect($document->fresh()->status)->toBe('pending');
});

it('does not touch synced_at on a second sync() when nothing changed', function () {
    Carbon::setTestNow('2026-01-01 00:00:00');

    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = createSpeaker($category, $brand);

    $firstSyncedAt = documentFor($product)->synced_at;

    Carbon::setTestNow('2026-01-01 00:05:00');
    $product->fresh(['category', 'brand', 'features'])->rag()->sync();

    expect(documentFor($product)->synced_at->equalTo($firstSyncedAt))->toBeTrue();
});
