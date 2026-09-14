<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Jobs\ForgetRagDocument;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Companion to TransactionBoundaryTest: proves the `deleted` lifecycle hook
 * itself (HasRag::bootHasRag(), not the raw dispatch primitive) respects
 * ADR-0006 for deletion, the gap tracked by the "make RAG document deletion
 * transaction-safe" issue. Uses the default 'sync' queue connection (see
 * TestCase::defineEnvironment()): unlike the 'database' driver, 'sync'
 * performs no DB write of its own for the job, so there is no risk of the
 * false pass TransactionBoundaryTest guards against — asserting directly
 * on rag_documents here is a clean test of the after-commit boundary.
 */
function createSpeakerForDeletion(): Product
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

function ragDocumentExistsFor(Product $product): bool
{
    return RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->exists();
}

it('keeps the rag document when a transaction wrapping delete() rolls back', function () {
    $product = createSpeakerForDeletion();
    expect(ragDocumentExistsFor($product))->toBeTrue();

    try {
        DB::transaction(function () use ($product) {
            $product->delete();

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Product::find($product->id))->not->toBeNull();
    expect(ragDocumentExistsFor($product))->toBeTrue();
});

it('deletes the rag document once a transaction wrapping delete() commits', function () {
    $product = createSpeakerForDeletion();
    expect(ragDocumentExistsFor($product))->toBeTrue();

    DB::transaction(function () use ($product) {
        $product->delete();
    });

    expect(ragDocumentExistsFor($product))->toBeFalse();
});

it('dispatches ForgetRagDocument after commit rather than forgetting inline from the deleted event', function () {
    $product = createSpeakerForDeletion();

    Bus::fake();

    $product->delete();

    Bus::assertDispatched(ForgetRagDocument::class, function (ForgetRagDocument $job) use ($product) {
        return $job->pairs === [['model_type' => Product::class, 'model_id' => $product->id]];
    });
});
