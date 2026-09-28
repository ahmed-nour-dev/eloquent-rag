<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Rag;
use Ahmednour\EloquentRag\RagSearch;
use Ahmednour\EloquentRag\RagSearchResult;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Ahmednour\EloquentRag\VectorBackendCapability;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

/**
 * The opt-in portable fallback (issue #63) on this suite's SQLite
 * connection — a real fallback candidate, so these tests exercise the
 * genuine embed()/search path end to end, not a mock of it. Embeddings are
 * faked deterministically: each text maps to a small vector by keyword, so
 * relevance order is predictable.
 */
function fallbackKeywordVector(string $text): array
{
    return [
        stripos($text, 'speaker') !== false ? 1.0 : 0.0,
        stripos($text, 'laptop') !== false ? 1.0 : 0.0,
        0.1,
    ];
}

function fakeFallbackKeywordEmbeddings(): void
{
    Embeddings::fake(fn ($prompt): array => array_map(fallbackKeywordVector(...), $prompt->inputs));
}

function makeFallbackProduct(string $name, string $sku): Product
{
    return Product::create([
        'name' => $name,
        'sku' => $sku,
        'price' => 10,
        'category_id' => Category::firstOrCreate(['name' => 'Electronics'])->id,
        'brand_id' => Brand::firstOrCreate(['name' => 'Acme'])->id,
    ]);
}

beforeEach(function () {
    config([
        'eloquent-rag.portable_fallback.enabled' => true,
        'eloquent-rag.embedding.dimensions' => 3,
    ]);
});

it('only uses the fallback when it is enabled', function () {
    expect(VectorBackendCapability::usesPortableFallback())->toBeTrue();

    config(['eloquent-rag.portable_fallback.enabled' => false]);

    expect(VectorBackendCapability::usesPortableFallback())->toBeFalse();
    expect(fn () => VectorBackendCapability::ensureUsable())->toThrow(UnsupportedVectorBackend::class);
});

it('treats SQLite as a fallback candidate', function () {
    expect(VectorBackendCapability::isPortableFallbackCandidate(DB::connection()))->toBeTrue();
});

it('embeds chunks as JSON and marks the document synced', function () {
    fakeFallbackKeywordEmbeddings();

    $product = makeFallbackProduct('Speaker', 'SPK-1');
    $product->rag()->embed();

    $chunk = RagChunk::query()->firstOrFail();

    expect($chunk->embedding)->toBe([1.0, 0.0, 0.1]);
    expect($chunk->embedding_dimensions)->toBe(3);
    expect(RagDocument::query()->value('status'))->toBe('synced');
});

it('ranks search results by cosine similarity in PHP', function () {
    fakeFallbackKeywordEmbeddings();

    $speaker = makeFallbackProduct('Speaker', 'SPK-1');
    $laptop = makeFallbackProduct('Laptop', 'LAP-1');
    $speaker->rag()->embed();
    $laptop->rag()->embed();

    expect(Product::searchRag('a laptop')->pluck('id')->all())->toBe([$laptop->id, $speaker->id]);
    expect(Rag::search(Product::class, 'speaker')->pluck('id')->all())->toBe([$speaker->id, $laptop->id]);
    expect(Product::searchRag('speaker', limit: 1)->pluck('id')->all())->toBe([$speaker->id]);
});

it('applies minSimilarity as a floor', function () {
    fakeFallbackKeywordEmbeddings();

    $speaker = makeFallbackProduct('Speaker', 'SPK-1');
    makeFallbackProduct('Laptop', 'LAP-1')->rag()->embed();
    $speaker->rag()->embed();

    expect(Product::searchRag('speaker', minSimilarity: 0.9)->pluck('id')->all())->toBe([$speaker->id]);
});

it('applies scope() filters on the fallback path too', function () {
    fakeFallbackKeywordEmbeddings();

    $speaker = makeFallbackProduct('Speaker', 'SPK-1');
    $laptop = makeFallbackProduct('Laptop', 'LAP-1');
    $speaker->rag()->embed();
    $laptop->rag()->embed();

    $results = (new RagSearch(Product::class))
        ->scope(fn ($query) => $query->where('rag_documents.model_id', '!=', $speaker->id))
        ->search('speaker');

    expect($results->pluck('id')->all())->toBe([$laptop->id]);
});

it('skips chunks that have not been embedded yet', function () {
    fakeFallbackKeywordEmbeddings();

    makeFallbackProduct('Speaker', 'SPK-1');

    expect(Product::searchRag('speaker'))->toBeEmpty();
});

it('returns scores and the best-matching chunk from searchRagWithScores()', function () {
    fakeFallbackKeywordEmbeddings();

    $speaker = makeFallbackProduct('Speaker', 'SPK-1');
    $laptop = makeFallbackProduct('Laptop', 'LAP-1');
    $speaker->rag()->embed();
    $laptop->rag()->embed();

    $results = Product::searchRagWithScores('speaker');

    expect($results)->toHaveCount(2);
    expect($results->first())->toBeInstanceOf(RagSearchResult::class);
    expect($results->first()->model->is($speaker))->toBeTrue();
    expect($results->first()->score)->toBeGreaterThan(0.99);
    expect($results->first()->score)->toBeGreaterThan($results->last()->score);
    expect($results->first()->distance)->toEqualWithDelta(1 - $results->first()->score, 1e-9);
    expect($results->first()->chunkIndex)->toBe(0);
    expect($results->first()->chunk())->toContain('name: Speaker');
    expect($results->first()->toArray())->toHaveKeys(['model', 'score', 'distance', 'chunk_index', 'chunk']);

    expect(Rag::searchWithScores(Product::class, 'laptop')->first()->model->is($laptop))->toBeTrue();
});

it('returns a null chunk rather than stale text when the model changed after embedding', function () {
    fakeFallbackKeywordEmbeddings();

    $speaker = makeFallbackProduct('Speaker', 'SPK-1');
    $speaker->rag()->embed();

    // A mass update bypasses model events, so the stored chunk still
    // describes the old content.
    Product::query()->whereKey($speaker->id)->update(['name' => 'Speaker Pro']);

    $result = Product::searchRagWithScores('speaker')->first();

    expect($result->model->getAttribute('name'))->toBe('Speaker Pro');
    expect($result->chunk())->toBeNull();
});
