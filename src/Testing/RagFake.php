<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Testing;

use Ahmednour\EloquentRag\RagDefinition;
use Ahmednour\EloquentRag\RagSearchResult;
use Ahmednour\EloquentRag\Support\RagDocumentBuilder;
use Ahmednour\EloquentRag\Support\RelationPathValidator;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Test double installed by Rag::fake() (issue #72). While it's active,
 * sync()/queue(), embed(), forget()/queueForget(), search, and dependency
 * invalidation are recorded instead of executed — no rag_* rows are
 * written, no embedding provider is called, and no vector-capable database
 * is needed — so an application can test its own HasRag models on
 * whatever database its test suite already uses.
 *
 * A faked sync still validates the definition's relation paths and
 * renders the document, exactly like the real sync() does before it
 * touches the database. That keeps the useful half of a sync —
 * "does my toRagDefinition() actually work against this model?" — and
 * makes the rendered text available to assertions.
 */
final class RagFake
{
    /** @var list<array{model: Model, rendered: string, forced: bool}> */
    private array $synced = [];

    /** @var list<Model> */
    private array $embedded = [];

    /** @var list<array{model_type: string, model_id: int|string}> */
    private array $forgotten = [];

    /** @var list<array{model_type: string, query: string, limit: int, min_similarity: float|null}> */
    private array $searches = [];

    /** @var list<array{dependency_type: string, dependency_ids: list<int|string>}> */
    private array $invalidations = [];

    /** @var array<string, array<int, Model>|Collection<int, Model>|Closure> */
    private array $searchResults = [];

    /**
     * Stubs what search returns for a model class. $results is a list of
     * models, or a Closure receiving (string $query, int $limit) and
     * returning one. Without a stub, search returns an empty result.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<int, Model>|Collection<int, Model>|Closure  $results
     */
    public function searchReturns(string $modelClass, array|Collection|Closure $results): self
    {
        $this->searchResults[$modelClass] = $results;

        return $this;
    }

    /**
     * Renders a model's document the way sync() would — validating every
     * declared relation path first — without writing anything. Useful for
     * asserting on toRagDefinition() output directly.
     */
    public function render(Model $model, ?RagDefinition $definition = null): string
    {
        if ($definition === null) {
            if (! method_exists($model, 'toRagDefinition')) {
                throw new InvalidArgumentException($model::class.' does not use HasRag, so it has no toRagDefinition() to render.');
            }

            $definition = $model->toRagDefinition();
        }

        $model->unsetRelations();

        foreach ($definition->relations() as $path) {
            RelationPathValidator::validate($model, $path);
        }

        return (new RagDocumentBuilder)->render($model, $definition);
    }

    /** @internal Called by RagSynchronizer while faking. */
    public function recordSync(Model $model, RagDefinition $definition, bool $forced = false): void
    {
        $this->synced[] = [
            'model' => $model,
            'rendered' => $this->render($model, $definition),
            'forced' => $forced,
        ];
    }

    /** @internal Called by RagSynchronizer while faking. */
    public function recordEmbed(Model $model): void
    {
        $this->embedded[] = $model;
    }

    /** @internal Called by RagSynchronizer while faking. */
    public function recordForget(Model $model): void
    {
        $this->forgotten[] = ['model_type' => $model::class, 'model_id' => $model->getKey()];
    }

    /**
     * @internal Called by DependencyInvalidator while faking.
     *
     * @param  list<int|string>  $ids
     */
    public function recordInvalidation(string $dependencyType, array $ids): void
    {
        $this->invalidations[] = ['dependency_type' => $dependencyType, 'dependency_ids' => $ids];
    }

    /**
     * @internal Called by RagSearch while faking.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function search(string $modelClass, string $query, int $limit, ?float $minSimilarity): EloquentCollection
    {
        $this->searches[] = [
            'model_type' => $modelClass,
            'query' => $query,
            'limit' => $limit,
            'min_similarity' => $minSimilarity,
        ];

        $results = $this->searchResults[$modelClass] ?? [];

        if ($results instanceof Closure) {
            $results = $results($query, $limit);
        }

        return (new $modelClass)->newCollection(
            collect($results)->take($limit)->values()->all(),
        );
    }

    /**
     * @internal Called by RagSearch while faking. Stubbed models come back
     * as perfect matches (score 1.0) with no chunk.
     *
     * @param  class-string<Model>  $modelClass
     * @return Collection<int, RagSearchResult>
     */
    public function searchWithScores(string $modelClass, string $query, int $limit, ?float $minSimilarity): Collection
    {
        return $this->search($modelClass, $query, $limit, $minSimilarity)
            ->toBase()
            ->map(fn (Model $model): RagSearchResult => new RagSearchResult($model, 1.0, 0.0))
            ->values();
    }

    /**
     * @param  Model|class-string<Model>  $model  A model instance (matched by
     *                                            class and key) or a class name.
     * @param  Closure|null  $callback  Receives the synced model (typed as
     *                                  your model class) and its rendered
     *                                  document text; return true to match.
     */
    public function assertSynced(Model|string $model, ?Closure $callback = null): void
    {
        PHPUnit::assertTrue(
            $this->syncedMatching($model, $callback)->isNotEmpty(),
            'The expected ['.$this->describe($model).'] RAG sync was not recorded.',
        );
    }

    /**
     * @param  Model|class-string<Model>  $model
     * @param  Closure|null  $callback  See assertSynced().
     */
    public function assertNotSynced(Model|string $model, ?Closure $callback = null): void
    {
        PHPUnit::assertTrue(
            $this->syncedMatching($model, $callback)->isEmpty(),
            'An unexpected ['.$this->describe($model).'] RAG sync was recorded.',
        );
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    public function assertSyncedTimes(Model|string $model, int $times): void
    {
        $count = $this->syncedMatching($model)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "The [{$this->describe($model)}] RAG sync was recorded {$count} time(s) instead of {$times}.",
        );
    }

    public function assertNothingSynced(): void
    {
        PHPUnit::assertEmpty($this->synced, count($this->synced).' unexpected RAG sync(s) were recorded.');
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    public function assertEmbedded(Model|string $model): void
    {
        PHPUnit::assertTrue(
            collect($this->embedded)->contains(fn (Model $embedded): bool => $this->matches($embedded, $model)),
            'The expected ['.$this->describe($model).'] RAG embedding was not recorded.',
        );
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    public function assertNotEmbedded(Model|string $model): void
    {
        PHPUnit::assertFalse(
            collect($this->embedded)->contains(fn (Model $embedded): bool => $this->matches($embedded, $model)),
            'An unexpected ['.$this->describe($model).'] RAG embedding was recorded.',
        );
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    public function assertForgotten(Model|string $model, int|string|null $id = null): void
    {
        [$type, $key] = $model instanceof Model ? [$model::class, $model->getKey()] : [$model, $id];

        PHPUnit::assertTrue(
            collect($this->forgotten)->contains(
                fn (array $pair): bool => $pair['model_type'] === $type && ($key === null || $pair['model_id'] == $key)
            ),
            "The expected [{$type}] RAG document removal was not recorded.",
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  (Closure(string, int, float|null): bool)|string|null  $query  The exact
     *                                                                       query, or a
     *                                                                       callback
     *                                                                       receiving
     *                                                                       (query, limit,
     *                                                                       minSimilarity).
     */
    public function assertSearched(string $modelClass, Closure|string|null $query = null): void
    {
        $matching = collect($this->searches)->filter(function (array $search) use ($modelClass, $query): bool {
            if ($search['model_type'] !== $modelClass) {
                return false;
            }

            return match (true) {
                $query === null => true,
                is_string($query) => $search['query'] === $query,
                default => (bool) $query($search['query'], $search['limit'], $search['min_similarity']),
            };
        });

        PHPUnit::assertTrue($matching->isNotEmpty(), "The expected [{$modelClass}] RAG search was not recorded.");
    }

    public function assertNothingSearched(): void
    {
        PHPUnit::assertEmpty($this->searches, count($this->searches).' unexpected RAG search(es) were recorded.');
    }

    public function assertInvalidated(string $dependencyType, int|string|null $id = null): void
    {
        PHPUnit::assertTrue(
            collect($this->invalidations)->contains(
                fn (array $invalidation): bool => $invalidation['dependency_type'] === $dependencyType
                    && ($id === null || in_array($id, $invalidation['dependency_ids'], false))
            ),
            "The expected [{$dependencyType}] RAG invalidation was not recorded.",
        );
    }

    /**
     * The rendered document text of the most recent recorded sync of this
     * model, or null if it was never synced.
     */
    public function renderedFor(Model $model): ?string
    {
        return $this->syncedMatching($model)->last()['rendered'] ?? null;
    }

    /**
     * @param  Model|class-string<Model>  $model
     * @return Collection<int, array{model: Model, rendered: string, forced: bool}>
     */
    private function syncedMatching(Model|string $model, ?Closure $callback = null): Collection
    {
        return collect($this->synced)->filter(
            fn (array $sync): bool => $this->matches($sync['model'], $model)
                && ($callback === null || $callback($sync['model'], $sync['rendered']))
        )->values();
    }

    /**
     * @param  Model|class-string<Model>  $expected
     */
    private function matches(Model $actual, Model|string $expected): bool
    {
        if (is_string($expected)) {
            return $actual instanceof $expected;
        }

        return $actual::class === $expected::class && $actual->getKey() == $expected->getKey();
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    private function describe(Model|string $model): string
    {
        return $model instanceof Model ? $model::class.' #'.$model->getKey() : $model;
    }
}
