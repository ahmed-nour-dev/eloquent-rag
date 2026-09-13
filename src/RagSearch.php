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
     * @return Collection<int, int|string>
     */
    private function rankedModelIds(array $vector, int $limit): Collection
    {
        $chunkDistances = DB::table('rag_chunks')
            ->join('rag_documents', 'rag_documents.id', '=', 'rag_chunks.document_id')
            ->where('rag_documents.model_type', $this->modelClass)
            ->whereNotNull('rag_chunks.embedding')
            ->when($this->scope, fn (Builder $builder) => ($this->scope)($builder))
            ->select('rag_documents.model_id')
            ->selectVectorDistance('rag_chunks.embedding', $vector, 'distance');

        return DB::query()
            ->fromSub($chunkDistances, 'ranked_chunks')
            ->select('ranked_chunks.model_id')
            ->groupBy('ranked_chunks.model_id')
            ->orderByRaw('MIN(ranked_chunks.distance) asc')
            ->limit($limit)
            ->pluck('ranked_chunks.model_id');
    }
}
