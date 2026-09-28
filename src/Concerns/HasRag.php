<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Concerns;

use Ahmednour\EloquentRag\RagDefinition;
use Ahmednour\EloquentRag\RagSearch;
use Ahmednour\EloquentRag\RagSearchResult;
use Ahmednour\EloquentRag\RagSynchronizer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Wires a model into the RAG sync lifecycle: created/updated/restored queue
 * a re-sync, deleted queues forgetting the document (chunks and
 * dependencies cascade via FK) — all of it deferred until after the
 * enclosing transaction commits. See ADR-0006 for the transaction/queue
 * boundary this relies on.
 */
trait HasRag
{
    /**
     * Declares how this model's document is rendered and what it depends
     * on (see ADR-0002 — declarative only, no relationship discovery).
     *
     * Named `toRagDefinition()` rather than the build plan's `rag()`
     * wording so it stays distinct from the runtime entry point below:
     * `rag()` returns a synchronizer bound to this definition, it is not
     * the definition itself.
     */
    abstract public function toRagDefinition(): RagDefinition;

    public function rag(): RagSynchronizer
    {
        return new RagSynchronizer($this, $this->toRagDefinition());
    }

    /**
     * Manually re-syncs this model's dependency rows. Required after any
     * belongsToMany attach()/detach()/sync() call on a declared relation
     * dependency — per ADR-0007, Eloquent fires no pivot events, so there
     * is nothing for the package to observe automatically.
     */
    public function resyncRag(): void
    {
        $this->rag()->sync();
    }

    /**
     * `$this->{$relation}()->attach(...)` followed by resyncRag(), as one
     * call — so the ADR-0007 pivot gotcha can't be forgotten at the call
     * site (issue #68).
     *
     * @param  mixed  $ids  Anything BelongsToMany::attach() accepts.
     * @param  array<string, mixed>  $attributes
     */
    public function attachRag(string $relation, mixed $ids, array $attributes = [], bool $touch = true): void
    {
        $this->ragPivotRelation($relation)->attach($ids, $attributes, $touch);

        $this->resyncRag();
    }

    /**
     * `$this->{$relation}()->detach(...)` followed by resyncRag().
     *
     * @param  mixed  $ids  Anything BelongsToMany::detach() accepts; null
     *                      detaches everything.
     * @return int The number of detached records.
     */
    public function detachRag(string $relation, mixed $ids = null, bool $touch = true): int
    {
        $detached = $this->ragPivotRelation($relation)->detach($ids, $touch);

        $this->resyncRag();

        return $detached;
    }

    /**
     * `$this->{$relation}()->sync(...)` followed by resyncRag(). (Named
     * syncRagRelation() rather than syncRag() to keep it visibly distinct
     * from rag()->sync(), which re-syncs the document, not a relation.)
     *
     * @param  mixed  $ids  Anything BelongsToMany::sync() accepts.
     * @return array{attached: array<int, mixed>, detached: array<int, mixed>, updated: array<int, mixed>}
     */
    public function syncRagRelation(string $relation, mixed $ids, bool $detaching = true): array
    {
        $changes = $this->ragPivotRelation($relation)->sync($ids, $detaching);

        $this->resyncRag();

        return $changes;
    }

    /**
     * @return BelongsToMany<Model, $this>
     */
    private function ragPivotRelation(string $relation): BelongsToMany
    {
        $instance = method_exists($this, $relation) ? $this->{$relation}() : null;

        if (! $instance instanceof BelongsToMany) {
            throw new InvalidArgumentException(sprintf(
                '%s::%s() is not a belongsToMany relation — attachRag()/detachRag()/syncRagRelation() only apply to pivot relations.',
                static::class,
                $relation,
            ));
        }

        return $instance;
    }

    /**
     * Vector search scoped to this model type, returning hydrated models
     * ordered by relevance — see RagSearch.
     *
     * The build plan describes this as `Product::rag()->search(...)`, but
     * that literal shape is not possible in PHP once `rag()` above already
     * exists as a real instance method: a class cannot declare the same
     * method name as both instance and static, and PHP raises a hard
     * "cannot call non-static method statically" error rather than falling
     * through to __callStatic. `searchRag()` is the static entry point
     * instead; `Rag::search(static::class, ...)` is the equivalent
     * class-agnostic form.
     *
     * @param  int  $limit  Must be at least 1; values above
     *                      config('eloquent-rag.search.max_limit') are
     *                      silently clamped — see RagSearch::search().
     * @param  float|null  $minSimilarity  Minimum cosine similarity (0.0-1.0)
     *                                     a chunk must meet to be considered
     *                                     a match — see RagSearch::search().
     */
    public static function searchRag(string $query, int $limit = 10, ?float $minSimilarity = null): EloquentCollection
    {
        return (new RagSearch(static::class))->search($query, $limit, $minSimilarity);
    }

    /**
     * Like searchRag(), but returns one RagSearchResult per match — the
     * hydrated model, its cosine similarity `score`, and its best-matching
     * chunk (`chunkIndex`, `chunk()`) — for grounding an LLM answer or
     * showing citations. See RagSearch::searchWithScores().
     *
     * @return Collection<int, RagSearchResult>
     */
    public static function searchRagWithScores(string $query, int $limit = 10, ?float $minSimilarity = null): Collection
    {
        return (new RagSearch(static::class))->searchWithScores($query, $limit, $minSimilarity);
    }

    protected static function bootHasRag(): void
    {
        static::created(fn (self $model) => $model->rag()->queue());
        static::updated(fn (self $model) => $model->rag()->queue());
        static::deleted(fn (self $model) => $model->rag()->queueForget());

        // `restored` has no dedicated static convenience method on the base
        // Model — only SoftDeletes defines one — so it's registered via the
        // underlying mechanism directly. This is harmless and simply never
        // fires on a model that doesn't use SoftDeletes.
        static::registerModelEvent('restored', fn (self $model) => $model->rag()->queue());
    }
}
