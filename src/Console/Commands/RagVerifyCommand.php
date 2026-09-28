<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Console\Commands;

use Ahmednour\EloquentRag\RagSynchronizer;
use Ahmednour\EloquentRag\Support\RagConnectionResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Reconciles stored documents against a fresh render (issue #68). The two
 * biggest gotchas in this package — a belongsToMany attach()/detach()
 * without resyncRag(), and a mass update/bulk insert without
 * Rag::invalidate() — fail silently and leave nothing behind for rag:doctor
 * to find. This command finds their *result* instead: a document whose
 * stored content_hash, configuration_hash, or dependency rows no longer
 * match what sync() would produce from current data. It can't say which
 * write path caused the drift, only that it exists.
 *
 * Read-only unless --fix is given, in which case every drifted (or, with a
 * model argument, missing) document is re-synced. Exits non-zero when it
 * finds drift it didn't fix, so it can run on a schedule or in CI.
 */
class RagVerifyCommand extends Command
{
    protected $signature = 'rag:verify
        {model? : Fully-qualified model class to verify (default: every model type in rag_documents)}
        {--sample= : Check only this many randomly chosen documents per model type instead of all of them}
        {--fix : Re-sync every drifted document (and, with a model argument, every model that has no document)}
        {--connection= : Connection to discover rag_documents model types from when no model argument is given (default: the app\'s default connection)}';

    protected $description = 'Detect documents that have drifted from their model data (e.g. a forgotten resyncRag() or Rag::invalidate()), optionally re-syncing them';

    private const REPORT_LIMIT = 20;

    /** @var list<array{string, string, string}> */
    private array $report = [];

    private int $checked = 0;

    private int $drifted = 0;

    private int $fixed = 0;

    private int $orphaned = 0;

    public function handle(): int
    {
        // The command instance is resolved once per application and reused
        // across calls (a scheduler, a long-running worker, tests), so the
        // tallies must start fresh on every run.
        $this->report = [];
        $this->checked = $this->drifted = $this->fixed = $this->orphaned = 0;

        $model = $this->argument('model');
        $sample = $this->option('sample');

        if ($sample !== null && (! ctype_digit((string) $sample) || (int) $sample < 1)) {
            $this->error('--sample must be a positive integer.');

            return self::FAILURE;
        }

        $sample = $sample !== null ? (int) $sample : null;

        if ($model !== null && ! class_exists($model)) {
            $this->error("Model class {$model} does not exist.");

            return self::FAILURE;
        }

        $modelTypes = $model !== null
            ? [$model]
            : DB::connection($this->option('connection'))->table('rag_documents')->distinct()->pluck('model_type')->all();

        foreach ($modelTypes as $modelType) {
            if (! class_exists($modelType)) {
                $this->warn("  Skipping {$modelType} — class no longer exists.");

                continue;
            }

            $this->verifyDocuments($modelType, $sample);

            // Owner rows with no document at all (e.g. created through a
            // bulk insert that fired no events). Only when a model type is
            // named explicitly: it walks the whole owner table, which isn't
            // something to do for every indexed type by default.
            if ($model !== null && $sample === null) {
                $this->verifyMissingDocuments($modelType);
            }
        }

        $this->printReport();

        $unfixed = $this->drifted - $this->fixed;

        $this->newLine();
        $this->info("Checked: {$this->checked}, Drifted: {$this->drifted}, Fixed: {$this->fixed}, Orphaned: {$this->orphaned}");

        if ($this->orphaned > 0) {
            $this->warn("{$this->orphaned} document(s) belong to a model row that no longer exists — run `php artisan rag:prune` to remove them.");
        }

        if ($unfixed > 0) {
            $this->warn("{$unfixed} drifted document(s) were not fixed — re-run with --fix to re-sync them. Drift usually means a belongsToMany attach()/detach() without resyncRag() (or attachRag()/detachRag()), or a mass update/bulk insert without Rag::invalidate() — see docs/fanout-behavior.md#the-mass-update-and-pivot-limitations.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelType
     */
    private function verifyDocuments(string $modelType, ?int $sample): void
    {
        $query = DB::connection(RagConnectionResolver::resolve($modelType))
            ->table('rag_documents')
            ->where('model_type', $modelType)
            ->select('id', 'model_id');

        if ($sample !== null) {
            $this->verifyRows($modelType, $query->inRandomOrder()->limit($sample)->get()->all());

            return;
        }

        $query->orderBy('id')->chunkById(200, function ($rows) use ($modelType): void {
            $this->verifyRows($modelType, $rows->all());
        });
    }

    /**
     * @param  class-string<Model>  $modelType
     * @param  array<int, object>  $rows
     */
    private function verifyRows(string $modelType, array $rows): void
    {
        $keyName = (new $modelType)->getKeyName();

        $models = $modelType::query()
            ->whereIn($keyName, array_map(fn (object $row) => $row->model_id, $rows))
            ->get()
            ->keyBy(fn (Model $model) => (string) $model->getKey());

        foreach ($rows as $row) {
            $this->checked++;

            $model = $models->get((string) $row->model_id);

            if ($model === null) {
                $this->orphaned++;
                $this->addReport($modelType, $row->model_id, 'orphaned (model row deleted)');

                continue;
            }

            $this->verifyModel($model);
        }
    }

    /**
     * @param  class-string<Model>  $modelType
     */
    private function verifyMissingDocuments(string $modelType): void
    {
        $connection = RagConnectionResolver::resolve($modelType);

        $modelType::query()->chunkById(200, function ($models) use ($modelType, $connection): void {
            $documented = DB::connection($connection)->table('rag_documents')
                ->where('model_type', $modelType)
                ->whereIn('model_id', $models->map(fn (Model $model) => $model->getKey())->all())
                ->pluck('model_id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            foreach ($models as $model) {
                if (in_array((string) $model->getKey(), $documented, true)) {
                    continue;
                }

                $this->checked++;
                $this->recordDrift($model, ['missing']);
            }
        });
    }

    private function verifyModel(Model $model): void
    {
        try {
            $drift = $this->synchronizer($model)->inspect();
        } catch (Throwable $e) {
            $this->drifted++;
            $this->addReport($model::class, $model->getKey(), 'error: '.$e->getMessage());

            return;
        }

        if ($drift !== []) {
            $this->recordDrift($model, $drift);
        }
    }

    /**
     * @param  list<string>  $drift
     */
    private function recordDrift(Model $model, array $drift): void
    {
        $this->drifted++;
        $status = implode(', ', $drift);

        if ($this->option('fix')) {
            try {
                $this->synchronizer($model)->sync();
                $this->fixed++;
                $status .= ' — re-synced';
            } catch (Throwable $e) {
                $status .= ' — re-sync failed: '.$e->getMessage();
            }
        }

        $this->addReport($model::class, $model->getKey(), $status);
    }

    private function synchronizer(Model $model): RagSynchronizer
    {
        if (! method_exists($model, 'rag')) {
            throw new InvalidArgumentException($model::class.' does not use the HasRag trait.');
        }

        return $model->rag();
    }

    private function addReport(string $modelType, mixed $id, string $status): void
    {
        $this->report[] = [$modelType, (string) $id, $status];
    }

    private function printReport(): void
    {
        if ($this->report === []) {
            return;
        }

        $this->table(['Model', 'ID', 'Drift'], array_slice($this->report, 0, self::REPORT_LIMIT));

        if (count($this->report) > self::REPORT_LIMIT) {
            $this->line('  … and '.(count($this->report) - self::REPORT_LIMIT).' more.');
        }
    }
}
