<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Ahmednour\EloquentRag\Exceptions\InvalidEmbeddingResponse;
use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Ahmednour\EloquentRag\Jobs\ForgetRagDocument;
use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDependency;
use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Support\Chunker;
use Ahmednour\EloquentRag\Support\Hasher;
use Ahmednour\EloquentRag\Support\RagConnectionResolver;
use Ahmednour\EloquentRag\Support\RagDocumentBuilder;
use Ahmednour\EloquentRag\Support\RelationPathValidator;
use Ahmednour\EloquentRag\Support\TokenizerFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
     *
     * $force = true (used by rag:rebuild) skips the short-circuit entirely
     * — always re-render/re-chunk/re-reconcile — and bumps `version` on an
     * existing document. A normal (non-forced) sync never touches
     * `version`, exactly as before this parameter existed.
     *
     * The document upsert + chunk reconcile + dependency reconcile run
     * inside a single transaction so a failure partway through never
     * leaves a document row pointing at partially-reconciled chunks or
     * dependencies — this is what makes rag:rebuild a safe replacement
     * rather than delete-and-pray. This method itself never catches its
     * own exceptions; callers that need per-model failure isolation across
     * a batch (rag:sync, rag:rebuild) are responsible for their own
     * try/catch around each call.
     */
    public function sync(bool $force = false): void
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

        // Fails loudly on a mistyped or restructured declared path (per
        // ADR-0002) before anything is rendered, hashed, or written —
        // rather than the path silently resolving to an empty dependency
        // set further down in reconcileDependencies().
        foreach ($this->definition->relations() as $path) {
            RelationPathValidator::validate($this->model, $path);
        }

        $rendered = (new RagDocumentBuilder)->render($this->model, $this->definition);
        $chunkOptions = $this->chunkOptions();

        $contentHash = Hasher::content($rendered);
        $configurationHash = Hasher::configuration(
            $this->definition,
            $chunkOptions,
            config('eloquent-rag.embedding.provider'),
            (string) config('eloquent-rag.embedding.model'),
            (int) config('eloquent-rag.embedding.dimensions'),
        );

        $existing = $this->findDocument();

        if (! $force
            && $existing !== null
            && $existing->content_hash === $contentHash
            && $existing->configuration_hash === $configurationHash
        ) {
            return;
        }

        // A changed configuration_hash means the embedding provider, model,
        // or dimensions changed (or the chunking rules did) — either way,
        // every chunk's already-stored embedding was generated under the
        // old configuration and is no longer valid, even for chunks whose
        // content_hash hasn't changed. reconcileChunks() needs this to null
        // out those otherwise-untouched embeddings too.
        $configurationChanged = $existing !== null && $existing->configuration_hash !== $configurationHash;

        $connectionName = $this->connectionName();

        DB::connection($connectionName)->transaction(function () use ($connectionName, $existing, $force, $rendered, $chunkOptions, $contentHash, $configurationHash, $configurationChanged): void {
            $values = [
                'content_hash' => $contentHash,
                'configuration_hash' => $configurationHash,
                // Phase 2 never produces a real embedding, so nothing is
                // ever fully "synced" yet — Phase 3 introduces the status
                // that means "actually embedded". A successful sync also
                // clears any previously recorded failure.
                'status' => 'pending',
                'last_error' => null,
                'synced_at' => now(),
            ];

            if ($force && $existing !== null) {
                $values['version'] = $existing->version + 1;
            }

            $document = RagDocument::on($connectionName)->updateOrCreate(
                [
                    'model_type' => $this->model::class,
                    'model_id' => $this->model->getKey(),
                ],
                $values,
            );

            $this->reconcileChunks($document, $rendered, $chunkOptions, $configurationChanged);
            $this->reconcileDependencies($document);
        });
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
     * Dispatches the document deletion after the enclosing transaction
     * commits (ADR-0006) — the same protection queue() gives create/update
     * /restore. Deleting the document directly from the `deleted` event,
     * synchronously and inside whatever transaction the caller's delete()
     * happens to be wrapped in, would leave the RAG document gone even if
     * that transaction later rolls back and the model row comes back.
     */
    public function queueForget(): void
    {
        ForgetRagDocument::dispatch([
            ['model_type' => $this->model::class, 'model_id' => $this->model->getKey()],
        ])->afterCommit();
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
     * @throws InvalidEmbeddingResponse
     */
    public function embed(): void
    {
        $connectionName = $this->connectionName();

        VectorBackendCapability::ensureSupported($connectionName);

        $document = $this->findDocument();

        if ($document === null) {
            return;
        }

        $pendingChunks = $document->chunks()->whereNull('embedding')->orderBy('chunk_index')->get();

        if ($pendingChunks->isNotEmpty()) {
            $this->model->unsetRelations();
            $rendered = (new RagDocumentBuilder)->render($this->model, $this->definition);
            $chunkOptions = $this->chunkOptions();
            $chunkTexts = (new Chunker($chunkOptions['max_tokens'], $chunkOptions['overlap'], TokenizerFactory::make()))->chunk($rendered);

            // A stored chunk's index can be missing from the freshly
            // re-rendered/re-chunked $chunkTexts when this document's true
            // current state has drifted from the snapshot embed() started
            // from (a concurrent sync() mid-flight, or content that shrank
            // the chunk count). Embedding '' for it would silently persist
            // a real vector for empty content. Instead, leave it out of
            // this pass entirely — its embedding stays NULL, so it's picked
            // up again once a sync() reconciles chunks against a consistent
            // snapshot, and the status update below already refuses to
            // mark the document 'synced' while any embedding is NULL.
            $embeddableChunks = $pendingChunks
                ->filter(fn (RagChunk $chunk): bool => array_key_exists($chunk->chunk_index, $chunkTexts))
                ->values();

            if ($embeddableChunks->isNotEmpty()) {
                $inputs = $embeddableChunks
                    ->map(fn (RagChunk $chunk): string => $chunkTexts[$chunk->chunk_index])
                    ->all();

                $provider = config('eloquent-rag.embedding.provider');
                $model = (string) config('eloquent-rag.embedding.model');
                $dimensions = (int) config('eloquent-rag.embedding.dimensions');

                // The raw (possibly-null) provider config still goes to
                // generate() unchanged — laravel/ai resolves a null provider to
                // its own configured default internally. What gets *stored* as
                // provenance below is the resolved value, so embedding_provider
                // is never a silent null on a written chunk.
                $resolvedProvider = $provider ?? config('ai.default_for_embeddings');

                // Cheap re-check immediately before paying for the provider call
                // (network latency, cost, rate-limit consumption): a concurrent
                // sync() can land between the read at the top of embed() and
                // here, having already changed the document's rendered content
                // out from under $chunkTexts/$inputs above. The per-chunk
                // content_hash gate below already stops a stale response from
                // being *persisted*, but by then the round-trip is spent for
                // nothing — bail out here instead, before dispatching it, and
                // let a later embed() pass pick these chunks up against the
                // now-current content (see issue #56).
                $stillCurrent = RagDocument::on($connectionName)
                    ->where('id', $document->id)
                    ->where('content_hash', $document->content_hash)
                    ->where('configuration_hash', $document->configuration_hash)
                    ->exists();

                if ($stillCurrent) {
                    $response = Embeddings::for($inputs)
                        ->dimensions($dimensions)
                        ->generate($provider, $model);

                    // laravel/ai does not guarantee this positionally on every code
                    // path (its own count check only fires under individual
                    // caching — see InvalidEmbeddingResponse's docblock). Validate
                    // before touching the database: a short/long response would
                    // otherwise misalign $response->embeddings[$index] against
                    // $embeddableChunks below, and a wrong-width vector would get
                    // persisted as-is into a fixed-width vector column.
                    if (count($response->embeddings) !== count($inputs)) {
                        throw InvalidEmbeddingResponse::countMismatch(count($inputs), count($response->embeddings));
                    }

                    foreach ($response->embeddings as $index => $embedding) {
                        if (count($embedding) !== $dimensions) {
                            throw InvalidEmbeddingResponse::dimensionMismatch($index, $dimensions, count($embedding));
                        }
                    }

                    foreach ($embeddableChunks->values() as $index => $chunk) {
                        // A concurrent sync() can reconcile this exact chunk between
                        // the read above and this write, changing its content_hash
                        // and re-nulling its embedding (reconcileChunks()). Re-fetch
                        // gated on the content_hash $inputs was actually built from:
                        // if it no longer matches, the row has moved on and writing
                        // this embedding would pair a fresh content_hash with a
                        // vector computed from stale text — something
                        // whereNull('embedding') would never catch again. Update
                        // through the fetched *model* rather than a query-builder
                        // mass update so AsVector's cast still applies; a plain
                        // array write bypasses it and MariaDB rejects the value.
                        $document->chunks()
                            ->where('id', $chunk->id)
                            ->where('content_hash', $chunk->content_hash)
                            ->first()
                            ?->update([
                                'embedding' => $response->embeddings[$index],
                                'embedding_provider' => $resolvedProvider,
                                'embedding_model' => $model,
                                'embedding_dimensions' => $dimensions,
                                'embedding_hash' => Hasher::embedding($chunk->content_hash, $resolvedProvider, $model, $dimensions),
                            ]);
                    }
                }
            }
        }

        // A plain check-then-act ($document->chunks()->whereNull(...)->doesntExist()
        // followed by a separate update()) leaves a window for a concurrent
        // sync() to null out a chunk's embedding between the two statements,
        // flipping this document to 'synced' against a snapshot that no
        // longer exists. Folding the "no NULL embeddings" check into the
        // UPDATE's own WHERE (via whereDoesntHave, a NOT EXISTS subquery)
        // makes it atomic, and gating on the content/configuration hashes
        // this call started from means a sync() that changes the document's
        // shape in between — whether it lands before or after this
        // statement — can never be papered over by a stale "all NULL-free"
        // read: either the hashes no longer match (no-op here) or they
        // still match, in which case nothing relevant changed.
        RagDocument::on($connectionName)
            ->where('id', $document->id)
            ->where('content_hash', $document->content_hash)
            ->where('configuration_hash', $document->configuration_hash)
            ->whereDoesntHave('chunks', fn ($query) => $query->whereNull('embedding'))
            ->update(['status' => 'synced']);
    }

    private function findDocument(): ?RagDocument
    {
        return RagDocument::on($this->connectionName())
            ->where('model_type', $this->model::class)
            ->where('model_id', $this->model->getKey())
            ->first();
    }

    private function connectionName(): ?string
    {
        return RagConnectionResolver::resolve($this->model);
    }

    /** @return array{max_tokens: int, overlap: int, tokenizer: string} */
    private function chunkOptions(): array
    {
        return [
            'max_tokens' => (int) config('eloquent-rag.chunk.max_tokens', 400),
            'overlap' => (int) config('eloquent-rag.chunk.overlap', 40),
            'tokenizer' => TokenizerFactory::make()->identifier(),
        ];
    }

    /**
     * @param  array{max_tokens: int, overlap: int, tokenizer: string}  $chunkOptions
     */
    private function reconcileChunks(RagDocument $document, string $rendered, array $chunkOptions, bool $configurationChanged = false): void
    {
        $chunker = new Chunker($chunkOptions['max_tokens'], $chunkOptions['overlap'], TokenizerFactory::make());
        $chunks = $chunker->chunk($rendered);

        $existingChunks = $document->chunks()->get()->keyBy('chunk_index');

        foreach ($chunks as $index => $chunkText) {
            $contentHash = Hasher::content($chunkText);
            $existingChunk = $existingChunks->get($index);

            $values = ['content_hash' => $contentHash];

            // Null the embedding whenever it no longer matches what would
            // be generated now: either the chunk text itself changed, or
            // the embedding provider/model/dimensions changed underneath
            // an unchanged chunk (configurationChanged) — both leave a
            // stale vector that embed() (which only fills chunks where
            // embedding IS NULL) would otherwise never regenerate.
            if ($existingChunk !== null && ($configurationChanged || $existingChunk->content_hash !== $contentHash)) {
                $values['embedding'] = null;
                $values['embedding_provider'] = null;
                $values['embedding_model'] = null;
                $values['embedding_dimensions'] = null;
                $values['embedding_hash'] = null;
            }

            $document->chunks()->updateOrCreate(
                ['chunk_index' => $index],
                $values,
            );
        }

        // Chunk count shrank: drop whatever no longer has a slot.
        $document->chunks()->where('chunk_index', '>=', count($chunks))->delete();
    }

    /**
     * Delta reconciliation: derive the desired dependency set, diff it
     * against the rows already on the document, delete only the rows that
     * are no longer desired, and insert only the ones that are new. Rows
     * present in both sets are left untouched (no id/timestamp churn) —
     * this is what keeps a resync of a document with a large, largely
     * unchanged dependency graph cheap instead of always paying for
     * N deletes + N inserts.
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

        $key = fn (string $type, int|string $id): string => $type.':'.$id;

        $desired = $dependencies->keyBy(
            fn (array $dependency) => $key($dependency['dependency_type'], $dependency['dependency_id'])
        );

        // ->toBase() drops down to a plain Support Collection: Eloquent
        // Collection overrides except()/only() to filter by the model's
        // primary key rather than the keyBy() key, which would silently
        // break the diff below.
        $existing = $document->dependencies()->get()->keyBy(
            fn (RagDependency $dependency) => $key($dependency->dependency_type, $dependency->dependency_id)
        )->toBase();

        $staleIds = $existing->except($desired->keys()->all())->pluck('id');

        if ($staleIds->isNotEmpty()) {
            $document->dependencies()->whereIn('id', $staleIds)->delete();
        }

        foreach ($desired->except($existing->keys()->all()) as $dependency) {
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
