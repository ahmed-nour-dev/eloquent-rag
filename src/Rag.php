<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class Rag
{
    public static function make(): RagDefinition
    {
        return RagDefinition::make();
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
}
