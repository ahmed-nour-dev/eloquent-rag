<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Exceptions\FallbackCandidateLimitExceeded;
use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\RagSearch;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Laravel\Ai\Embeddings;

/**
 * ADR-0011: the opt-in PHP-side fallback, exercised against this package's
 * own real SQLite test connection — a genuine ADR-0003-unsupported
 * backend, same connection EmbeddingAndSearchTest.php uses to prove the
 * *rejection* path, but here with config('eloquent-rag.fallback.enabled')
 * turned on to prove the *fallback ranking* path instead.
 *
 * Uses the same exact-geometry unit-vector technique as
 * tests/Integration/Postgres/PostgresAcceptanceTest.php (identical vectors
 * are always cosine similarity 1.0, orthogonal always exactly 0.0, opposite
 * always exactly -1.0 — not an approximation), so these tests assert the
 * fallback ranking is behaviorally identical to the native path's
 * documented semantics, not merely "returns something".
 */
beforeEach(function () {
    config(['eloquent-rag.fallback.enabled' => true]);
});

function createFallbackTestProduct(string $name = 'Speaker', string $sku = 'SPK-001'): Product
{
    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);

    return Product::create([
        'name' => $name,
        'sku' => $sku,
        'price' => 49.99,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
    ]);
}

function fallbackTestDocumentIdFor(Product $product): int
{
    return RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->value('id');
}

/** @return array<int, float> */
function fallbackTestUnitVector(int $onIndex, float $value = 1.0): array
{
    $dimensions = (int) config('eloquent-rag.embedding.dimensions');

    return array_replace(array_fill(0, $dimensions, 0.0), [$onIndex => $value]);
}

it('embeds successfully on the SQLite connection once the fallback is enabled', function () {
    Embeddings::fake();

    $product = createFallbackTestProduct();

    $product->rag()->embed();

    $document = RagDocument::query()
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->first();

    expect($document->status)->toBe('synced');
    expect($document->chunks()->whereNull('embedding')->exists())->toBeFalse();
});

it('does not let near-duplicate chunks in one document crowd out a genuine second-best document', function () {
    $bestMatch = createFallbackTestProduct('Best Match', 'DOC-A');
    $secondBest = createFallbackTestProduct('Second Best', 'DOC-B');
    $worstMatch = createFallbackTestProduct('Worst Match', 'DOC-C');

    // Document A: 8 chunks identical to the query vector (distance 0 each)
    // — many near-duplicate top chunks belonging to one document.
    foreach (range(0, 7) as $offset) {
        RagChunk::create([
            'document_id' => fallbackTestDocumentIdFor($bestMatch),
            'chunk_index' => 100 + $offset,
            'content_hash' => str_repeat('a', 64),
            'embedding' => fallbackTestUnitVector(0),
        ]);
    }

    // Document B: a single chunk orthogonal to the query (distance 1) —
    // the genuine second-best document.
    RagChunk::create([
        'document_id' => fallbackTestDocumentIdFor($secondBest),
        'chunk_index' => 100,
        'content_hash' => str_repeat('b', 64),
        'embedding' => fallbackTestUnitVector(1),
    ]);

    // Document C: a single chunk pointing the opposite way (distance 2).
    RagChunk::create([
        'document_id' => fallbackTestDocumentIdFor($worstMatch),
        'chunk_index' => 100,
        'content_hash' => str_repeat('c', 64),
        'embedding' => fallbackTestUnitVector(0, -1.0),
    ]);

    Embeddings::fake([[fallbackTestUnitVector(0)]]);

    $results = Product::searchRag('anything', 2);

    expect($results->pluck('id')->all())->toBe([$bestMatch->id, $secondBest->id]);
});

it('excludes a document whose best chunk falls below the minSimilarity floor', function () {
    $match = createFallbackTestProduct('Best Match', 'DOC-A');
    $tooFar = createFallbackTestProduct('Too Far', 'DOC-B');

    RagChunk::create([
        'document_id' => fallbackTestDocumentIdFor($match),
        'chunk_index' => 100,
        'content_hash' => str_repeat('a', 64),
        'embedding' => fallbackTestUnitVector(0),
    ]);

    // Orthogonal to the query vector: cosine similarity exactly 0.0 — below
    // the 0.5 floor below, so this document must be excluded entirely, not
    // merely ranked last.
    RagChunk::create([
        'document_id' => fallbackTestDocumentIdFor($tooFar),
        'chunk_index' => 100,
        'content_hash' => str_repeat('b', 64),
        'embedding' => fallbackTestUnitVector(1),
    ]);

    Embeddings::fake([[fallbackTestUnitVector(0)]]);

    $results = Product::searchRag('anything', 5, minSimilarity: 0.5);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('treats minSimilarity as an inclusive floor at the exact boundary', function () {
    $product = createFallbackTestProduct('Orthogonal Match', 'DOC-A');

    // Orthogonal to the query vector: cosine similarity exactly 0.0.
    RagChunk::create([
        'document_id' => fallbackTestDocumentIdFor($product),
        'chunk_index' => 100,
        'content_hash' => str_repeat('a', 64),
        'embedding' => fallbackTestUnitVector(1),
    ]);

    Embeddings::fake([[fallbackTestUnitVector(0)]]);

    $results = Product::searchRag('anything', 5, minSimilarity: 0.0);

    expect($results->pluck('id')->all())->toBe([$product->id]);
});

it('still applies scope() filtering in fallback mode', function () {
    $synced = createFallbackTestProduct('Synced', 'DOC-A');
    $notSynced = createFallbackTestProduct('Not Synced', 'DOC-B');

    RagDocument::query()->where('id', fallbackTestDocumentIdFor($synced))->update(['status' => 'synced']);
    RagDocument::query()->where('id', fallbackTestDocumentIdFor($notSynced))->update(['status' => 'pending']);

    RagChunk::create([
        'document_id' => fallbackTestDocumentIdFor($synced),
        'chunk_index' => 100,
        'content_hash' => str_repeat('a', 64),
        'embedding' => fallbackTestUnitVector(0),
    ]);

    RagChunk::create([
        'document_id' => fallbackTestDocumentIdFor($notSynced),
        'chunk_index' => 100,
        'content_hash' => str_repeat('b', 64),
        'embedding' => fallbackTestUnitVector(0),
    ]);

    Embeddings::fake([[fallbackTestUnitVector(0)]]);

    $results = (new RagSearch(Product::class))
        ->scope(fn ($query) => $query->where('rag_documents.status', 'synced'))
        ->search('anything', 5);

    expect($results->pluck('id')->all())->toBe([$synced->id]);
});

it('throws FallbackCandidateLimitExceeded when more chunks match than max_candidate_chunks allows', function () {
    config(['eloquent-rag.fallback.max_candidate_chunks' => 1]);

    $product = createFallbackTestProduct();

    foreach (range(0, 1) as $offset) {
        RagChunk::create([
            'document_id' => fallbackTestDocumentIdFor($product),
            'chunk_index' => 100 + $offset,
            'content_hash' => str_repeat((string) $offset, 64),
            'embedding' => fallbackTestUnitVector($offset),
        ]);
    }

    Embeddings::fake([[fallbackTestUnitVector(0)]]);

    expect(fn () => Product::searchRag('anything', 5))
        ->toThrow(FallbackCandidateLimitExceeded::class);
});
