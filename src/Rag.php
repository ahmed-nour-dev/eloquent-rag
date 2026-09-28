<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Ahmednour\EloquentRag\Testing\RagFake;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class Rag
{
    public static function make(): RagDefinition
    {
        return RagDefinition::make();
    }

    /**
     * Replaces sync/embed/forget/search/invalidation with a recording test
     * double for the rest of the current test (the fake lives in the
     * container, so each test's fresh application starts un-faked). Use it
     * to test your own HasRag models without a vector database or an
     * embedding provider — see docs/testing.md.
     */
    public static function fake(): RagFake
    {
        $fake = new RagFake;

        app()->instance(RagFake::class, $fake);

        return $fake;
    }

    /**
     * The active fake, or null when Rag::fake() hasn't been called.
     */
    public static function faking(): ?RagFake
    {
        return app()->bound(RagFake::class) ? app(RagFake::class) : null;
    }

    /**
     * Escape hatch for write paths that bypass Eloquent events entirely —
     * mass updates (`Category::where(...)->update()`) and bulk inserts —
     * per ADR-0006's documented limitation. Call this manually wherever
     * such a path changes something declared as a dependency.
     *
     * @param  int|string|array<int, int|string>  $dependencyIds
     */
    public static function invalidate(string $dependencyType, int|string|array $dependencyIds): void
    {
        app(DependencyInvalidator::class)->invalidate($dependencyType, $dependencyIds);
    }

    /**
     * Vector search scoped to a single owner model type, returning hydrated
     * models ordered by relevance. Equivalent to
     * `Product::searchRag($query, $limit)` — see HasRag::searchRag().
     *
     * @param  class-string  $modelClass
     * @param  int  $limit  Must be at least 1; values above
     *                      config('eloquent-rag.search.max_limit') are
     *                      silently clamped — see RagSearch::search().
     * @param  float|null  $minSimilarity  Minimum cosine similarity (0.0-1.0)
     *                                     a chunk must meet to be considered
     *                                     a match — see RagSearch::search().
     */
    public static function search(string $modelClass, string $query, int $limit = 10, ?float $minSimilarity = null): EloquentCollection
    {
        return (new RagSearch($modelClass))->search($query, $limit, $minSimilarity);
    }

    /**
     * Like search(), but returns one RagSearchResult per match — the
     * hydrated model, its cosine similarity score, and its best-matching
     * chunk — instead of bare models. Equivalent to
     * `Product::searchRagWithScores($query, $limit)`.
     *
     * @param  class-string  $modelClass
     * @return Collection<int, RagSearchResult>
     */
    public static function searchWithScores(string $modelClass, string $query, int $limit = 10, ?float $minSimilarity = null): Collection
    {
        return (new RagSearch($modelClass))->searchWithScores($query, $limit, $minSimilarity);
    }
}
