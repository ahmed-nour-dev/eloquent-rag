<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cleans up rag_documents rows (chunks/dependencies cascade via FK) whose
 * underlying model was deleted through a path that bypasses Eloquent
 * events entirely — a raw `DB::table(...)->delete()`, for instance. This
 * is the documented gap ADR-0006 already calls out (mass writes never
 * fire model events); rag:prune is the operational catch-up for it.
 */
class RagPruneCommand extends Command
{
    protected $signature = 'rag:prune';

    protected $description = 'Delete rag_documents (and cascading chunks/dependencies) whose underlying model row no longer exists';

    public function handle(): int
    {
        $modelTypes = DB::table('rag_documents')->distinct()->pluck('model_type');
        $prunedTotal = 0;

        foreach ($modelTypes as $modelType) {
            if (! class_exists($modelType)) {
                $this->warn("  Skipping {$modelType} — class no longer exists.");

                continue;
            }

            $prunedTotal += $this->pruneModelType($modelType);
        }

        $this->info("Pruned: {$prunedTotal} orphaned document(s).");

        return self::SUCCESS;
    }

    private function pruneModelType(string $modelType): int
    {
        $keyName = (new $modelType)->getKeyName();
        $pruned = 0;

        // chunkById (not chunk()) deliberately: this callback deletes rows
        // from the very table being chunked, and plain offset-based
        // chunk() would skip rows as earlier deletions shift later
        // offsets. chunkById re-queries "id > last seen id" each time,
        // which stays correct regardless of what's deleted behind it.
        DB::table('rag_documents')
            ->where('model_type', $modelType)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($modelType, $keyName, &$pruned): void {
                $ids = $rows->pluck('model_id')->all();

                $existingIds = $modelType::query()->whereIn($keyName, $ids)->pluck($keyName)->all();
                $missingIds = array_diff($ids, $existingIds);

                if ($missingIds === []) {
                    return;
                }

                $documentIdsToDelete = $rows
                    ->filter(fn (object $row): bool => in_array($row->model_id, $missingIds, false))
                    ->pluck('id')
                    ->all();

                DB::table('rag_documents')->whereIn('id', $documentIdsToDelete)->delete();

                $pruned += count($documentIdsToDelete);
            });

        return $pruned;
    }
}
