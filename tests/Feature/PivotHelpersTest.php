<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagDependency;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Feature;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;

/**
 * Issue #68: attachRag()/detachRag()/syncRagRelation() wrap the pivot
 * operation and resyncRag() in one call, so the ADR-0007 gotcha can't be
 * forgotten at the call site.
 */
function createPivotProduct(): Product
{
    return Product::create([
        'name' => 'Speaker',
        'sku' => 'SPK-1',
        'price' => 10,
        'category_id' => Category::create(['name' => 'Electronics'])->id,
        'brand_id' => Brand::create(['name' => 'Acme'])->id,
    ]);
}

function featureDependencyIds(Product $product): array
{
    return RagDependency::query()
        ->where('dependency_type', Feature::class)
        ->whereHas('document', fn ($query) => $query->where('model_id', $product->id))
        ->orderBy('dependency_id')
        ->pluck('dependency_id')
        ->map(fn ($id): int => (int) $id)
        ->all();
}

it('attaches and re-syncs in one call', function () {
    $product = createPivotProduct();
    $feature = Feature::create(['name' => 'Bluetooth']);

    $product->attachRag('features', $feature->id);

    expect(featureDependencyIds($product))->toBe([$feature->id]);
    expect($product->rag()->inspect())->toBe([]);
});

it('detaches and re-syncs in one call', function () {
    $product = createPivotProduct();
    $keep = Feature::create(['name' => 'Bluetooth']);
    $drop = Feature::create(['name' => 'Waterproof']);
    $product->attachRag('features', [$keep->id, $drop->id]);

    expect($product->detachRag('features', $drop->id))->toBe(1);

    expect(featureDependencyIds($product))->toBe([$keep->id]);
    expect($product->rag()->inspect())->toBe([]);
});

it('syncs a relation and re-syncs the document in one call', function () {
    $product = createPivotProduct();
    $a = Feature::create(['name' => 'Bluetooth']);
    $b = Feature::create(['name' => 'Waterproof']);
    $product->attachRag('features', $a->id);

    $changes = $product->syncRagRelation('features', [$b->id]);

    expect($changes['attached'])->toBe([$b->id]);
    expect($changes['detached'])->toBe([$a->id]);
    expect(featureDependencyIds($product))->toBe([$b->id]);
});

it('rejects a relation that is not belongsToMany', function () {
    $product = createPivotProduct();

    expect(fn () => $product->attachRag('category', 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => $product->attachRag('nope', 1))->toThrow(InvalidArgumentException::class);
});
