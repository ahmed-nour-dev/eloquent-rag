<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Exceptions\InvalidRelationPath;
use Ahmednour\EloquentRag\Rag;
use Ahmednour\EloquentRag\RagSynchronizer;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;

function makeSpeaker(): Product
{
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    return Product::create([
        'name' => 'Speaker',
        'sku' => 'SPK-001',
        'price' => 49.99,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
    ]);
}

it('fails loudly on sync() instead of silently registering an empty dependency set for a mistyped relation path', function () {
    $product = makeSpeaker();

    $definition = Rag::make()
        ->content(['name'])
        ->relation('categroy.name'); // typo, real fixture relation is "category"

    expect(fn () => (new RagSynchronizer($product, $definition))->sync())
        ->toThrow(InvalidRelationPath::class);
});

it('fails loudly on sync() when a declared relation path has no relation segment', function () {
    $product = makeSpeaker();

    $definition = Rag::make()
        ->content(['name'])
        ->relation('sku'); // own attribute, not a relation path

    expect(fn () => (new RagSynchronizer($product, $definition))->sync())
        ->toThrow(InvalidRelationPath::class);
});
