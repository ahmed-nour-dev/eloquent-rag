<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private readonly string $modelClass) {}

    /**
     * Applies an arbitrary filter to the underlying rag_chunks/rag_documents
     * query before ranking — e.g. ->scope(fn ($q) => $q->where('rag_documents.status', 'synced')).
     */
    public function scope(Closure $callback): self
    {
        $this->scope = $callback;

        return $this;
    }

    /**
     * @throws UnsupportedVectorBackend
     */
    public function search(string $query, int $limit = 10): EloquentCollection
    {
        VectorBackendCapability::ensureSupported();

        $vector = Embeddings::for([$query])
            ->dimensions((int) config('eloquent-rag.embedding.dimensions'))
            ->generate(
                config('eloquent-rag.embedding.provider'),
                config('eloquent-rag.embedding.model'),
            )
            ->first();

        $orderedIds = $this->rankedModelIds($vector, $limit);

        if ($orderedIds->isEmpty()) {
            return (new $this->modelClass)->newCollection();
        }

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
     * Ranks chunk-level rows by vector distance, then collapses to distinct
     * document owner-model ids in first-seen (best match) order. Chunk-level
     * rows are over-fetched by a fixed multiplier before deduping, since
     * several of the closest chunks can belong to the same document —
     * this is a simple, documented heuristic, not a guarantee of the true
     * top-N distinct documents in every distribution.
     *
     * @param  array<int, float>  $vector
     * @return Collection<int, int|string>
     */
    private function rankedModelIds(array $vector, int $limit): Collection
    {
        $overFetch = max($limit * 4, $limit);

        return DB::table('rag_chunks')
            ->join('rag_documents', 'rag_documents.id', '=', 'rag_chunks.document_id')
            ->where('rag_documents.model_type', $this->modelClass)
            ->whereNotNull('rag_chunks.embedding')
            ->when($this->scope, fn (Builder $builder) => ($this->scope)($builder))
            ->orderByVectorDistance('rag_chunks.embedding', $vector)
            ->limit($overFetch)
            ->pluck('rag_documents.model_id')
            ->unique()
            ->take($limit)
            ->values();
    }
}
