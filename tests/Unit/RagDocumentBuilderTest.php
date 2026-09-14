<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Rag;
use Ahmednour\EloquentRag\RagDefinition;
use Ahmednour\EloquentRag\Support\Hasher;
use Ahmednour\EloquentRag\Support\RagDocumentBuilder;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Feature;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Ahmednour\EloquentRag\Tests\Fixtures\StubTokenizer;

function makeProduct(): Product
{
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    // Created in reverse-alphabetical order and attached in that same
    // order, to prove rendering doesn't depend on insertion/attach order.
    $waterproof = Feature::create(['name' => 'Waterproof']);
    $bluetooth = Feature::create(['name' => 'Bluetooth']);

    $product = Product::create([
        'name' => 'Speaker',
        'sku' => 'SPK-001',
        'price' => 49.99,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
    ]);

    $product->features()->attach([$waterproof->id, $bluetooth->id]);

    return $product->fresh(['category', 'brand', 'features']);
}

function productDefinition(): RagDefinition
{
    return Rag::make()
        ->content(['name', 'sku', 'price'])
        ->relation('category.name')
        ->relation('brand.name')
        ->relation('features.name');
}

it('renders a byte-identical document across repeated calls for unchanged state', function () {
    $product = makeProduct();
    $builder = new RagDocumentBuilder;

    $first = $builder->render($product->fresh(['category', 'brand', 'features']), productDefinition());
    $second = $builder->render($product->fresh(['category', 'brand', 'features']), productDefinition());
    $third = $builder->render($product->fresh(['category', 'brand', 'features']), productDefinition());

    expect($first)->toBe($second)->toBe($third);
});

it('renders belongsToMany relation values in deterministic sorted order regardless of attach order', function () {
    $product = makeProduct();
    $builder = new RagDocumentBuilder;

    $rendered = $builder->render($product, productDefinition());

    expect($rendered)->toContain('features.name: Bluetooth, Waterproof');
});

it('produces a stable rendered document snapshot', function () {
    $product = makeProduct();
    $builder = new RagDocumentBuilder;

    $rendered = $builder->render($product, productDefinition());

    expect($rendered)->toBe(
        "name: Speaker\n".
        "sku: SPK-001\n".
        "price: 49.99\n".
        "category.name: Electronics\n".
        "brand.name: Acme\n".
        'features.name: Bluetooth, Waterproof'
    );
});

it('sorts an array-valued content attribute for determinism when not declared ordered', function () {
    $product = makeProduct();
    $product->setAttribute('steps', ['Step 3: Submit', 'Step 1: Login', 'Step 2: Verify']);

    $definition = Rag::make()->content(['steps']);
    $builder = new RagDocumentBuilder;

    $rendered = $builder->render($product, $definition);

    expect($rendered)->toBe('steps: Step 1: Login, Step 2: Verify, Step 3: Submit');
});

it('preserves declared order for a content attribute marked ordered', function () {
    $product = makeProduct();
    $product->setAttribute('steps', ['Step 3: Submit', 'Step 1: Login', 'Step 2: Verify']);

    $definition = Rag::make()->content(['steps'])->ordered('steps');
    $builder = new RagDocumentBuilder;

    $rendered = $builder->render($product, $definition);

    expect($rendered)->toBe('steps: Step 3: Submit, Step 1: Login, Step 2: Verify');
});

it('produces a different content_hash when source content changes', function () {
    $product = makeProduct();
    $builder = new RagDocumentBuilder;

    $before = $builder->render($product->fresh(['category', 'brand', 'features']), productDefinition());
    $product->update(['price' => 59.99]);
    $after = $builder->render($product->fresh(['category', 'brand', 'features']), productDefinition());

    expect(Hasher::content($before))->not->toBe(Hasher::content($after));
});

it('leaves content_hash unchanged when only chunk config changes, but changes configuration_hash', function () {
    $product = makeProduct();
    $builder = new RagDocumentBuilder;

    $renderedA = $builder->render($product->fresh(['category', 'brand', 'features']), productDefinition());
    $renderedB = $builder->render($product->fresh(['category', 'brand', 'features']), productDefinition());

    $configA = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-small',
        1536,
    );

    $configB = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 800, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-small',
        1536,
    );

    expect(Hasher::content($renderedA))->toBe(Hasher::content($renderedB));
    expect($configA)->not->toBe($configB);
});

it('changes configuration_hash when only the embedding provider changes', function () {
    $configA = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        'openai',
        'text-embedding-3-small',
        1536,
    );

    $configB = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        'ollama',
        'text-embedding-3-small',
        1536,
    );

    expect($configA)->not->toBe($configB);
});

it('changes configuration_hash when only the embedding model changes', function () {
    $configA = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-small',
        1536,
    );

    $configB = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-large',
        1536,
    );

    expect($configA)->not->toBe($configB);
});

it('changes configuration_hash when only the embedding dimensions change', function () {
    $configA = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-small',
        1536,
    );

    $configB = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-small',
        3072,
    );

    expect($configA)->not->toBe($configB);
});

it('produces the same configuration_hash regardless of associative key order in chunk options', function () {
    $configA = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-small',
        1536,
    );

    $configB = Hasher::configuration(
        productDefinition(),
        ['overlap' => 40, 'max_tokens' => 400, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-small',
        1536,
    );

    expect($configA)->toBe($configB);
});

it('changes configuration_hash when only a path\'s ordered declaration changes', function () {
    $configA = Hasher::configuration(
        Rag::make()->content(['name', 'sku', 'price'])->relation('features.name'),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-small',
        1536,
    );

    $configB = Hasher::configuration(
        Rag::make()->content(['name', 'sku', 'price'])->relation('features.name')->ordered('features.name'),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-small',
        1536,
    );

    expect($configA)->not->toBe($configB);
});

it('changes configuration_hash when only the tokenizer changes', function () {
    $configA = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
        null,
        'text-embedding-3-small',
        1536,
    );

    $configB = Hasher::configuration(
        productDefinition(),
        ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => StubTokenizer::class],
        null,
        'text-embedding-3-small',
        1536,
    );

    expect($configA)->not->toBe($configB);
});
