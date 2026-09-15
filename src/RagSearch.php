<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Ahmednour\EloquentRag\Support\RagConnectionResolver;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Ai\Embeddings;

/**
 * Vector search scoped to a single owner model type, returning hydrated
 * owner models (not RagDocument/RagChunk rows) ordered by relevance.
 *
 * Deliberately does NOT let orderByVectorDistance()'s built-in
 * Stringable::toEmbeddings() auto-conversion embed the query text: that
 * path uses laravel/ai's own default provider/model/dimensions, which can
 * silently mismatch the model/dimensions RagSynchronizer::embed() actually
 * used to embed the stored chunks. This class embeds the query explicitly
 * with the same config('eloquent-rag.embedding.*') values instead.
 */
final class RagSearch
{
    private ?Closure $scope = null;

    private readonly ?string $connectionName;

    public function __construct(private readonly string $modelClass)
    {
        $this->connectionName = RagConnectionResolver::resolve($this->modelClass);
    }

    /**
     * Applies an arbitrary filter to the underlying rag_chunks/rag_documents
     * query before ranking — e.g. ->scope(fn ($q) => $q->where('rag_documents.status', 'synced')).
     *
     * $callback receives the chunk-level query builder mid-construction
     * (see rankedModelIds()): the rag_chunks/rag_documents join and the
     * fixed model_type/whereNotNull('embedding') predicates are already
     * applied, and — when $minSimilarity is set — a
     * whereVectorDistanceLessThan() predicate is still to come, followed by
     * select('rag_documents.model_id') + selectVectorDistance(). The whole
     * result is then wrapped via fromSub() and grouped/ordered by
     * MIN(distance) per model_id in the outer query.
     *
     * This makes scope() **filter-only**. Safe: `where`/`whereHas`-style
     * predicates against rag_chunks/rag_documents columns. Unsupported —
     * these don't error, they silently produce wrong rankings:
     * - `orWhere` at the top level, which combines with the fixed
     *   model_type/whereNotNull predicates by operator precedence and can
     *   defeat them, matching chunks that shouldn't be there. Wrap it
     *   instead: ->where(fn ($q) => $q->where(...)->orWhere(...)).
     * - `select()`/`addSelect()`, `groupBy()`, `orderBy()` — the select,
     *   grouping, and ordering that make ranking work are applied by
     *   rankedModelIds() itself, after this callback runs; changing them
     *   here conflicts with (or duplicates) that.
     * - `limit()`/`offset()` — the result limit is applied once, on the
     *   outer aggregated query, not here.
     */
    public function scope(Closure $callback): self
    {
        $this->scope = $callback;

        return $this;
    }

    /**
     * @param  int  $limit  Must be at least 1. Values above
     *                      config('eloquent-rag.search.max_limit') are
     *                      silently clamped down to it rather than
     *                      rejected, to guard against accidentally
     *                      expensive vector queries.
     * @param  float|null  $minSimilarity  Minimum cosine similarity (0.0-1.0,
     *                                     where 1.0 is identical) a chunk
     *                                     must meet to be considered a
     *                                     match. Null (the default) applies
     *                                     no floor — the nearest `$limit`
     *                                     chunks are returned regardless of
     *                                     how distant they are.
     *
     * @throws UnsupportedVectorBackend
     * @throws InvalidArgumentException if $limit is below 1, or if
     *                                  $minSimilarity is outside [0.0, 1.0]
     */
    public function search(string $query, int $limit = 10, ?float $minSimilarity = null): EloquentCollection
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('$limit must be at least 1, got '.$limit.'.');
        }

        if ($minSimilarity !== null && ($minSimilarity < 0.0 || $minSimilarity > 1.0)) {
            throw new InvalidArgumentException('$minSimilarity must be between 0.0 and 1.0, got '.$minSimilarity.'.');
        }

        $limit = min($limit, (int) config('eloquent-rag.search.max_limit'));

        VectorBackendCapability::ensureSupported($this->connectionName);

        $vector = Embeddings::for([$query])
            ->dimensions((int) config('eloquent-rag.embedding.dimensions'))
            ->generate(
                config('eloquent-rag.embedding.provider'),
                config('eloquent-rag.embedding.model'),
            )
            ->first();

        $orderedIds = $this->rankedModelIds($vector, $limit, $minSimilarity);

        if ($orderedIds->isEmpty()) {
            return (new $this->modelClass)->newCollection();
        }

        // Deliberately $this->modelClass::query() — the searched model's
        // own Eloquent connection — not DB::connection($this->connectionName)
        // (the resolved RAG connection used for ranking above). These two
        // can differ by design: config('eloquent-rag.connection') can
        // centralize the RAG index on one connection while indexed models
        // still live on their own (e.g. per-tenant) connections. Owner rows
        // only ever exist on the model's own connection, so hydration must
        // always read them from there regardless of where the RAG index
        // itself lives — see
        // docs/installation.md#search-hydration-when-the-two-connections-differ
        // and the cross-connection case in tests/Integration/*/*AcceptanceTest.php
        // (issue #46).
        $models = $this->modelClass::query()->whereIn(
            (new $this->modelClass)->getKeyName(),
            $orderedIds->all(),
        )->get()->keyBy(fn ($model) => $model->getKey());

        // whereIn() does not guarantee result order across drivers, so the
        // relevance order established by the vector-distance query above
        // is re-applied here rather than trusted from the hydration query.
        return $orderedIds
            ->map(fn ($id) => $models->get($id))
            ->filter()
            ->values()
            ->pipe(fn ($items) => (new $this->modelClass)->newCollection($items->all()));
    }

    /**
     * Ranks document owner-model ids by a document-level aggregate of their
     * chunks' vector distances, rather than ranking chunk-level rows and
     * deduplicating down to documents. A document's score is the distance
     * of its single closest chunk (MIN), computed in SQL over every
     * matching chunk — so a document with several near-duplicate chunks
     * near the top does not crowd out a document whose one relevant chunk
     * scores lower, and the result is the true top-N distinct documents
     * for this vector, not a heuristic approximation of it.
     *
     * @param  array<int, float>  $vector
     * @param  float|null  $minSimilarity  See search()'s param doc.
     * @return Collection<int, int|string>
     */
    private function rankedModelIds(array $vector, int $limit, ?float $minSimilarity = null): Collection
    {
        $chunkDistances = DB::connection($this->connectionName)->table('rag_chunks')
            ->join('rag_documents', 'rag_documents.id', '=', 'rag_chunks.document_id')
            ->where('rag_documents.model_type', $this->modelClass)
            ->whereNotNull('rag_chunks.embedding')
            ->when($this->scope, fn (Builder $builder) => ($this->scope)($builder))
            ->when(
                $minSimilarity !== null,
                // A chunk that fails the floor shouldn't even count toward
                // "does this document have a good chunk" — filtering here,
                // before the MIN(distance) aggregate below, is equivalent to
                // (and cheaper than) aggregating first and filtering the
                // per-document minimum afterward.
                fn (Builder $builder) => $builder->whereVectorDistanceLessThan('rag_chunks.embedding', $vector, 1 - $minSimilarity),
            )
            ->select('rag_documents.model_id')
            ->selectVectorDistance('rag_chunks.embedding', $vector, 'distance');

        return DB::connection($this->connectionName)->query()
            ->fromSub($chunkDistances, 'ranked_chunks')
            ->select('ranked_chunks.model_id')
            ->groupBy('ranked_chunks.model_id')
            ->orderByRaw('MIN(ranked_chunks.distance) asc')
            ->limit($limit)
            ->pluck('ranked_chunks.model_id');
    }
}
