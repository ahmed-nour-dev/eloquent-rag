<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Support\Chunker;
use Ahmednour\EloquentRag\Support\Hasher;
use Ahmednour\EloquentRag\Support\RagDocumentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Embeddings;

/**
 * The one true sync routine for a single model instance, used identically
 * by the direct/queued path and by dependency fan-out (SyncRagDocument
 * calls back into this) — see the build plan's Phase 2 section and
 * ADR-0004/0005/0006 for the rules this implements.
 */
final class RagSynchronizer
{
    public function __construct(
        private readonly Model $model,
        private readonly RagDefinition $definition,
    ) {}

    /**
     * Renders, hashes, and (if anything actually changed) reconciles this
     * model's document, chunks, and dependency rows. Short-circuits on an
     * unchanged hash pair without touching the database at all.
     */
    public function sync(): void
    {
        // attach()/detach()/sync() on a belongsToMany relation (the
        // ADR-0007 resyncRag() case) update the pivot table but do not
        // clear the model's already-loaded relation cache. Rendering
        // against a stale cached relation would silently reuse the old
        // value, produce an unchanged content_hash, and short-circuit
        // before the dependency rows are ever reconciled. Clearing the
        // cache forces every relation the definition touches to be
        // re-read from current database state on every sync().
        $this->model->unsetRelations();

        $rendered = (new RagDocumentBuilder)->render($this->model, $this->definition);
        $chunkOptions = $this->chunkOptions();

        $contentHash = Hasher::content($rendered);
        $configurationHash = Hasher::configuration(
            $this->definition,
            $chunkOptions,
            (string) config('eloquent-rag.embedding.model'),
            (int) config('eloquent-rag.embedding.dimensions'),
        );

        $existing = $this->findDocument();

        if ($existing !== null
            && $existing->content_hash === $contentHash
            && $existing->configuration_hash === $configurationHash
        ) {
            return;
        }

        $document = RagDocument::query()->updateOrCreate(
            [
                'model_type' => $this->model::class,
                'model_id' => $this->model->getKey(),
            ],
            [
                'content_hash' => $contentHash,
                'configuration_hash' => $configurationHash,
                // Phase 2 never produces a real embedding, so nothing is
                // ever fully "synced" yet — Phase 3 introduces the status
                // that means "actually embedded".
                'status' => 'pending',
                'synced_at' => now(),
            ],
        );

        $this->reconcileChunks($document, $rendered, $chunkOptions);
        $this->reconcileDependencies($document);
    }

    /**
     * Dispatches a single-model sync batch after the enclosing transaction
     * commits (ADR-0006). This is the same job/batch shape dependency
     * fan-out uses — a batch of one.
     */
    public function queue(): void
    {
        SyncRagDocument::dispatch([
            ['model_type' => $this->model::class, 'model_id' => $this->model->getKey()],
        ])->afterCommit();
    }

    /**
     * Removes this model's document. Chunks and dependencies cascade via
     * the FK on rag_chunks.document_id / rag_dependencies.document_id.
     */
    public function forget(): void
    {
        $this->findDocument()?->delete();
    }

    /**
     * Generates real embeddings (via laravel/ai) for this document's chunks
     * that don't have one yet, and writes them to rag_chunks.embedding.
     *
     * Deliberately NOT called from sync() — sync() stays exactly as
     * Phase 2 built it (pure structural reconciliation, works on any
     * driver including SQLite). embed() is the Phase 3 addition, and it
     * requires a real vector-capable backend; call sync() first if source
     * content may have changed, since chunk text isn't persisted and is
     * re-derived here assuming the currently-stored chunk_index values are
     * still current.
     *
     * @throws UnsupportedVectorBackend
     */
    public function embed(): void
    {
        VectorBackendCapability::ensureSupported();

        $document = $this->findDocument();

        if ($document === null) {
            return;
        }

        $pendingChunks = $document->chunks()->whereNull('embedding')->orderBy('chunk_index')->get();

        if ($pendingChunks->isNotEmpty()) {
            $this->model->unsetRelations();
            $rendered = (new RagDocumentBuilder)->render($this->model, $this->definition);
            $chunkOptions = $this->chunkOptions();
            $chunkTexts = (new Chunker($chunkOptions['max_tokens'], $chunkOptions['overlap']))->chunk($rendered);

            $inputs = $pendingChunks
                ->map(fn (RagChunk $chunk): string => $chunkTexts[$chunk->chunk_index] ?? '')
                ->all();

            $response = Embeddings::for($inputs)
                ->dimensions((int) config('eloquent-rag.embedding.dimensions'))
                ->generate(
                    config('eloquent-rag.embedding.provider'),
                    config('eloquent-rag.embedding.model'),
                );

            foreach ($pendingChunks->values() as $index => $chunk) {
                $chunk->update(['embedding' => $response->embeddings[$index]]);
            }
        }

        if ($document->chunks()->whereNull('embedding')->doesntExist()) {
            $document->update(['status' => 'synced']);
        }
    }

    private function findDocument(): ?RagDocument
    {
        return RagDocument::query()
            ->where('model_type', $this->model::class)
            ->where('model_id', $this->model->getKey())
            ->first();
    }

    /** @return array{max_tokens: int, overlap: int} */
    private function chunkOptions(): array
    {
        return [
            'max_tokens' => (int) config('eloquent-rag.chunk.max_tokens', 400),
            'overlap' => (int) config('eloquent-rag.chunk.overlap', 40),
        ];
    }

    /**
     * @param  array{max_tokens: int, overlap: int}  $chunkOptions
     */
    private function reconcileChunks(RagDocument $document, string $rendered, array $chunkOptions): void
    {
        $chunker = new Chunker($chunkOptions['max_tokens'], $chunkOptions['overlap']);
        $chunks = $chunker->chunk($rendered);

        foreach ($chunks as $index => $chunkText) {
            $document->chunks()->updateOrCreate(
                ['chunk_index' => $index],
                ['content_hash' => Hasher::content($chunkText)],
            );
        }

        // Chunk count shrank: drop whatever no longer has a slot.
        $document->chunks()->where('chunk_index', '>=', count($chunks))->delete();
    }

    /**
     * Full reconciliation, not a delta patch — matches the Phase 0 spike's
     * validated approach (item 8): delete this document's current
     * dependency rows and re-derive the desired set from scratch.
     */
    private function reconcileDependencies(RagDocument $document): void
    {
        $dependencies = collect();

        foreach ($this->definition->relations() as $path) {
            // Drop the leaf attribute — the document depends on the
            // related *model*, not its individual field.
            $segments = explode('.', $path);
            array_pop($segments);

            foreach ($this->resolveRelatedModels($this->model, $segments) as $related) {
                $dependencies->push([
                    'dependency_type' => $related::class,
                    'dependency_id' => $related->getKey(),
                ]);
            }
        }

        $dependencies = $dependencies->unique(
            fn (array $dependency): string => $dependency['dependency_type'].':'.$dependency['dependency_id']
        );

        $document->dependencies()->delete();

        foreach ($dependencies as $dependency) {
            $document->dependencies()->create($dependency);
        }
    }

    /**
     * Walks a relation-path prefix (with the leaf attribute already
     * removed) down to the related model instance(s) it denotes. A
     * belongsToMany collection fans out to every related model in it.
     *
     * @param  list<string>  $segments
     * @return list<Model>
     */
    private function resolveRelatedModels(mixed $value, array $segments): array
    {
        if ($value === null) {
            return [];
        }

        if ($segments === []) {
            if ($value instanceof EloquentCollection) {
                return $value->all();
            }

            return $value instanceof Model ? [$value] : [];
        }

        if ($value instanceof EloquentCollection) {
            return $value->flatMap(
                fn (Model $item): array => $this->resolveRelatedModels($item, $segments)
            )->all();
        }

        $segment = array_shift($segments);
        $next = $value instanceof Model ? $value->{$segment} : null;

        return $this->resolveRelatedModels($next, $segments);
    }
}
