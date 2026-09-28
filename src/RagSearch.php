<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Ahmednour\EloquentRag\Support\RagConnectionResolver;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
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
 *
 * @phpstan-type RankedRow array{model_id: int|string, distance: float, chunk_index: int|null, content_hash: string|null}
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
     * (see chunkQuery()): the rag_chunks/rag_documents join and the fixed
     * model_type/whereNotNull('embedding') predicates are already applied,
     * and — when $minSimilarity is set — a whereVectorDistanceLessThan()
     * predicate is still to come, followed by select('rag_documents.model_id')
     * + selectVectorDistance(). The whole result is then wrapped via
     * fromSub() and grouped/ordered by MIN(distance) per model_id in the
     * outer query (rankNatively()). Under the portable fallback the same
     * scoped query is instead streamed into PHP and ranked there
     * (rankInPhp()), so the same filters apply either way.
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
     *   the ranking methods themselves, after this callback runs; changing them
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
        $ranked = $this->rank($query, $limit, $minSimilarity, withChunks: false);
        $models = $this->hydrate($ranked->pluck('model_id'));

        // whereIn() does not guarantee result order across drivers, so the
        // relevance order established by the ranking query is re-applied
        // here rather than trusted from the hydration query.
        return $ranked
            ->map(fn (array $row) => $models->get($row['model_id']))
            ->filter()
            ->values()
            ->pipe(fn ($items) => (new $this->modelClass)->newCollection($items->all()));
    }

    /**
     * Same ranking, limit, and $minSimilarity semantics as search(), but
     * returns one RagSearchResult per match — the hydrated model plus its
     * similarity score and best-matching chunk — instead of bare models.
     * Use this when grounding an LLM answer or showing citations.
     *
     * @return Collection<int, RagSearchResult>
     *
     * @throws UnsupportedVectorBackend
     * @throws InvalidArgumentException see search()
     */
    public function searchWithScores(string $query, int $limit = 10, ?float $minSimilarity = null): Collection
    {
        $ranked = $this->rank($query, $limit, $minSimilarity, withChunks: true);
        $models = $this->hydrate($ranked->pluck('model_id'));

        return $ranked
            ->filter(fn (array $row): bool => $models->has($row['model_id']))
            ->map(fn (array $row): RagSearchResult => new RagSearchResult(
                model: $models->get($row['model_id']),
                score: 1 - $row['distance'],
                distance: $row['distance'],
                chunkIndex: $row['chunk_index'],
                chunkContentHash: $row['content_hash'],
            ))
            ->values();
    }

    /**
     * Validates arguments, embeds the query, and ranks owner-model ids —
     * natively on a supported backend, or in PHP when the opt-in portable
     * fallback applies to this connection.
     *
     * @return Collection<int, RankedRow>
     */
    private function rank(string $query, int $limit, ?float $minSimilarity, bool $withChunks): Collection
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('$limit must be at least 1, got '.$limit.'.');
        }

        if ($minSimilarity !== null && ($minSimilarity < 0.0 || $minSimilarity > 1.0)) {
            throw new InvalidArgumentException('$minSimilarity must be between 0.0 and 1.0, got '.$minSimilarity.'.');
        }

        $limit = min($limit, (int) config('eloquent-rag.search.max_limit'));

        VectorBackendCapability::ensureUsable($this->connectionName);

        $vector = Embeddings::for([$query])
            ->dimensions((int) config('eloquent-rag.embedding.dimensions'))
            ->generate(
                config('eloquent-rag.embedding.provider'),
                config('eloquent-rag.embedding.model'),
            )
            ->first();

        if (VectorBackendCapability::usesPortableFallback($this->connectionName)) {
            return $this->rankInPhp($vector, $limit, $minSimilarity);
        }

        $ranked = $this->rankNatively($vector, $limit, $minSimilarity);

        return $withChunks ? $this->attachBestChunks($ranked, $vector, $minSimilarity) : $ranked;
    }

    /**
     * @param  Collection<int, int|string>  $ids
     * @return Collection<int|string, Model>
     */
    private function hydrate(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        // Deliberately $this->modelClass::query() — the searched model's
        // own Eloquent connection — not DB::connection($this->connectionName)
        // (the resolved RAG connection used for ranking). These two
        // can differ by design: config('eloquent-rag.connection') can
        // centralize the RAG index on one connection while indexed models
        // still live on their own (e.g. per-tenant) connections. Owner rows
        // only ever exist on the model's own connection, so hydration must
        // always read them from there regardless of where the RAG index
        // itself lives — see
        // docs/installation.md#search-hydration-when-the-two-connections-differ
        // and the cross-connection case in tests/Integration/*/*AcceptanceTest.php
        // (issue #46).
        return $this->modelClass::query()->whereIn(
            (new $this->modelClass)->getKeyName(),
            $ids->all(),
        )->get()->keyBy(fn ($model) => $model->getKey())->toBase();
    }

    /**
     * The chunk-level query every ranking path starts from: this model
     * type's embedded chunks, with the caller's scope() applied.
     */
    private function chunkQuery(): Builder
    {
        return DB::connection($this->connectionName)->table('rag_chunks')
            ->join('rag_documents', 'rag_documents.id', '=', 'rag_chunks.document_id')
            ->where('rag_documents.model_type', $this->modelClass)
            ->whereNotNull('rag_chunks.embedding')
            ->when($this->scope, fn (Builder $builder) => ($this->scope)($builder));
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function chunkDistanceQuery(array $vector, ?float $minSimilarity): Builder
    {
        return $this->chunkQuery()
            ->when(
                $minSimilarity !== null,
                // A chunk that fails the floor shouldn't even count toward
                // "does this document have a good chunk" — filtering here,
                // before the MIN(distance) aggregate, is equivalent to (and
                // cheaper than) aggregating first and filtering the
                // per-document minimum afterward.
                fn (Builder $builder) => $builder->whereVectorDistanceLessThan('rag_chunks.embedding', $vector, 1 - $minSimilarity),
            )
            ->select('rag_documents.model_id')
            ->selectVectorDistance('rag_chunks.embedding', $vector, 'distance');
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
     * @return Collection<int, RankedRow>
     */
    private function rankNatively(array $vector, int $limit, ?float $minSimilarity): Collection
    {
        return DB::connection($this->connectionName)->query()
            ->fromSub($this->chunkDistanceQuery($vector, $minSimilarity), 'ranked_chunks')
            ->select('ranked_chunks.model_id')
            ->selectRaw('MIN(ranked_chunks.distance) as best_distance')
            ->groupBy('ranked_chunks.model_id')
            ->orderByRaw('MIN(ranked_chunks.distance) asc')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->rankedRow($this->normalizeId($row->model_id), (float) $row->best_distance));
    }

    /**
     * Second, narrow query for searchWithScores() only (search() never
     * pays for it): the chunk-level distances of just the already-ranked
     * documents, reduced in PHP to each document's single closest chunk —
     * the same chunk whose distance the MIN() aggregate picked.
     *
     * @param  Collection<int, RankedRow>  $ranked
     * @param  array<int, float>  $vector
     * @return Collection<int, RankedRow>
     */
    private function attachBestChunks(Collection $ranked, array $vector, ?float $minSimilarity): Collection
    {
        if ($ranked->isEmpty()) {
            return $ranked;
        }

        $best = [];

        $rows = $this->chunkDistanceQuery($vector, $minSimilarity)
            ->addSelect('rag_chunks.chunk_index', 'rag_chunks.content_hash')
            ->whereIn('rag_documents.model_id', $ranked->pluck('model_id')->all())
            ->get();

        foreach ($rows as $row) {
            $key = (string) $row->model_id;

            if (! isset($best[$key]) || (float) $row->distance < (float) $best[$key]->distance) {
                $best[$key] = $row;
            }
        }

        return $ranked->map(function (array $row) use ($best): array {
            $chunk = $best[(string) $row['model_id']] ?? null;

            return $this->rankedRow(
                $row['model_id'],
                $row['distance'],
                $chunk !== null ? (int) $chunk->chunk_index : null,
                $chunk !== null ? (string) $chunk->content_hash : null,
            );
        });
    }

    /**
     * The portable fallback's ranking: the same "each document scores as
     * its single closest chunk" semantics as rankNatively(), computed in
     * PHP over JSON-decoded embeddings. Reads every embedded chunk of this
     * model type (streamed in pages, keeping only each document's best
     * chunk in memory), so cost is linear in the index size — the reason
     * this path is opt-in and documented as development/small-data only.
     *
     * @param  array<int, float>  $vector
     * @return Collection<int, RankedRow>
     */
    private function rankInPhp(array $vector, int $limit, ?float $minSimilarity): Collection
    {
        $queryNorm = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector)));

        if ($queryNorm == 0.0) {
            return collect();
        }

        $maxDistance = $minSimilarity !== null ? 1 - $minSimilarity : null;
        $best = [];

        $chunks = $this->chunkQuery()
            ->select('rag_chunks.id', 'rag_documents.model_id', 'rag_chunks.chunk_index', 'rag_chunks.content_hash', 'rag_chunks.embedding')
            ->lazyById(1000, 'rag_chunks.id', 'id');

        foreach ($chunks as $chunk) {
            $embedding = json_decode((string) $chunk->embedding, true);

            if (! is_array($embedding) || count($embedding) !== count($vector)) {
                continue;
            }

            $distance = $this->cosineDistance($vector, $queryNorm, array_values($embedding));

            if ($distance === null || ($maxDistance !== null && $distance > $maxDistance)) {
                continue;
            }

            $key = (string) $chunk->model_id;

            if (! isset($best[$key]) || $distance < $best[$key]['distance']) {
                $best[$key] = $this->rankedRow(
                    $this->normalizeId($chunk->model_id),
                    $distance,
                    (int) $chunk->chunk_index,
                    (string) $chunk->content_hash,
                );
            }
        }

        usort($best, fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

        return collect(array_slice($best, 0, $limit));
    }

    /**
     * @return RankedRow
     */
    private function rankedRow(int|string $modelId, float $distance, ?int $chunkIndex = null, ?string $contentHash = null): array
    {
        return [
            'model_id' => $modelId,
            'distance' => $distance,
            'chunk_index' => $chunkIndex,
            'content_hash' => $contentHash,
        ];
    }

    /**
     * rag_documents.model_id comes back from the driver untyped — an int
     * on some drivers, a numeric string on others, a real string for
     * string-keyed models.
     */
    private function normalizeId(mixed $id): int|string
    {
        return is_int($id) ? $id : (string) $id;
    }

    /**
     * @param  array<int, float>  $query
     * @param  array<int, mixed>  $candidate
     */
    private function cosineDistance(array $query, float $queryNorm, array $candidate): ?float
    {
        $dot = 0.0;
        $candidateNormSquared = 0.0;

        foreach ($query as $i => $value) {
            $other = (float) $candidate[$i];
            $dot += $value * $other;
            $candidateNormSquared += $other * $other;
        }

        if ($candidateNormSquared == 0.0) {
            return null;
        }

        return 1 - $dot / ($queryNorm * sqrt($candidateNormSquared));
    }
}
