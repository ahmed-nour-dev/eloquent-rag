<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Exceptions\InvalidRelationPath;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Rag;
use Ahmednour\EloquentRag\RagSearchResult;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\BrokenProduct;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Laravel\Ai\Embeddings;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Issue #72: Rag::fake() lets an application test its own HasRag models
 * without a vector database or an embedding provider.
 */
function createFakedProduct(string $name = 'Speaker'): Product
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
    Embeddings::fake();
});

it('records lifecycle syncs with the rendered document instead of writing rag rows', function () {
    $fake = Rag::fake();

    $product = createFakedProduct();

    $fake->assertSynced($product);
    $fake->assertSynced(Product::class, fn (Product $synced, string $rendered): bool => str_contains($rendered, 'category.name: Electronics'));
    $fake->assertSyncedTimes($product, 1);
    expect($fake->renderedFor($product))->toContain('name: Speaker');
    expect(RagDocument::query()->count())->toBe(0);
});

it('records syncs regardless of the queue driver', function () {
    config(['queue.default' => 'database']);
    $fake = Rag::fake();

    $fake->assertSynced(createFakedProduct());
});

it('surfaces a broken definition from a faked sync', function () {
    Rag::fake();

    $product = createFakedProduct();
    $broken = BrokenProduct::query()->findOrFail($product->id);

    expect(fn () => $broken->rag()->sync())->toThrow(InvalidRelationPath::class);
    expect(fn () => Rag::faking()->render($broken))->toThrow(InvalidRelationPath::class);
});

it('records embeds, forgets, and invalidations', function () {
    $fake = Rag::fake();

    $product = createFakedProduct();
    $product->rag()->embed();
    $fake->assertEmbedded($product);

    Rag::invalidate(Category::class, [5, 6]);
    $fake->assertInvalidated(Category::class, 6);

    $id = $product->id;
    $product->delete();
    $fake->assertForgotten(Product::class, $id);

    Embeddings::assertNothingGenerated();
});

it('returns stubbed search results and records the search', function () {
    $fake = Rag::fake();

    $speaker = createFakedProduct('Speaker');
    $laptop = createFakedProduct('Laptop');

    expect(Product::searchRag('anything'))->toBeEmpty();

    $fake->searchReturns(Product::class, [$speaker, $laptop]);
    expect(Product::searchRag('speakers', limit: 1)->pluck('id')->all())->toBe([$speaker->id]);

    $fake->searchReturns(Product::class, fn (string $query): array => $query === 'laptops' ? [$laptop] : []);
    expect(Rag::search(Product::class, 'laptops')->pluck('id')->all())->toBe([$laptop->id]);

    $results = Product::searchRagWithScores('laptops');
    expect($results->first())->toBeInstanceOf(RagSearchResult::class);
    expect($results->first()->model->is($laptop))->toBeTrue();

    $fake->assertSearched(Product::class, 'speakers');
    $fake->assertSearched(Product::class, fn (string $query, int $limit): bool => $query === 'speakers' && $limit === 1);
    Embeddings::assertNothingGenerated();
});

it('still validates search arguments while faking', function () {
    Rag::fake();

    expect(fn () => Product::searchRag('x', limit: 0))->toThrow(InvalidArgumentException::class);
});

it('fails assertions that did not happen', function () {
    $fake = Rag::fake();

    $fake->assertNothingSynced();
    $fake->assertNothingSearched();
    $fake->assertNotSynced(Product::class);

    expect(fn () => $fake->assertSynced(Product::class))->toThrow(AssertionFailedError::class);
    expect(fn () => $fake->assertEmbedded(Product::class))->toThrow(AssertionFailedError::class);
    expect(fn () => $fake->assertSearched(Product::class))->toThrow(AssertionFailedError::class);
});

it('is not active unless Rag::fake() was called', function () {
    expect(Rag::faking())->toBeNull();

    createFakedProduct();

    expect(RagDocument::query()->count())->toBe(1);
});
