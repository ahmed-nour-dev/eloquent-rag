<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Shared per-model sync/embed + failure-isolation loop for rag:sync and
 * rag:rebuild — the only difference between them is whether sync() is
 * called with $force. One bad model must never abort an entire batch: each
 * model gets its own try/catch, and a failure is recorded on its document
 * row (if one exists — see recordFailure()) rather than propagated.
 *
 * @mixin Command
 */
trait ProcessesRagModels
{
    /**
     * @return array{synced: int, failed: int}
     */
    protected function processModels(?string $modelClass, int|string|null $id, bool $force): array
    {
        $synced = 0;
        $failed = 0;

        $modelClasses = $modelClass !== null ? [$modelClass] : $this->distinctModelTypes();

        if ($modelClasses === []) {
            $this->warn('No model types found in rag_documents yet — nothing to target. Run this against a specific model class at least once first.');
        }

        foreach ($modelClasses as $class) {
            if ($id !== null) {
                $model = $class::find($id);

                if ($model === null) {
                    $this->warn("  {$class} #{$id} not found — skipping.");

                    continue;
                }

                $this->processOne($model, $force) ? $synced++ : $failed++;

                continue;
            }

            $class::query()->chunkById(200, function ($models) use ($force, &$synced, &$failed): void {
                foreach ($models as $model) {
                    $this->processOne($model, $force) ? $synced++ : $failed++;
                }
            });
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    private function processOne(Model $model, bool $force): bool
    {
        $modelClass = $model::class;

        try {
            $model->rag()->sync($force);
        } catch (Throwable $e) {
            $this->warn("  Failed (sync): {$modelClass} #{$model->getKey()}: {$e->getMessage()}");
            $this->recordFailure($model, $e);

            return false;
        }

        try {
            $model->rag()->embed();
        } catch (Throwable $e) {
            $this->warn("  Failed (embed): {$modelClass} #{$model->getKey()}: {$e->getMessage()}");
            $this->recordFailure($model, $e);

            return false;
        }

        return true;
    }

    /**
     * Records the failure on the model's document row if one exists. If
     * sync() itself failed before ever creating a document (e.g. rendering
     * threw on the very first sync), there is nothing to attach the error
     * to — update() simply affects zero rows in that case, which is
     * correct: the failure is still visible in this command's own output
     * and failed count, without forcing a row into existence just to hold
     * an error.
     */
    private function recordFailure(Model $model, Throwable $e): void
    {
        DB::table('rag_documents')
            ->where('model_type', $model::class)
            ->where('model_id', $model->getKey())
            ->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
    }

    /**
     * @return list<string>
     */
    private function distinctModelTypes(): array
    {
        return DB::table('rag_documents')->distinct()->pluck('model_type')->all();
    }
}
