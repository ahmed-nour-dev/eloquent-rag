<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a dependency change (e.g. "Category #5 was saved") to the
 * documents that declared it as a dependency, and dispatches bounded,
 * ID-only, coalesced fan-out — see ADR-0005. This is what
 * Rag::invalidate() and the global model-saved listener both call.
 */
final class DependencyInvalidator
{
    /**
     * @param  int|string|array<int, int|string>  $dependencyIds
     */
    public function invalidate(string $dependencyType, int|string|array $dependencyIds): void
    {
        $ids = is_array($dependencyIds) ? array_values($dependencyIds) : [$dependencyIds];

        if ($ids === []) {
            return;
        }

        // Cheap existence check before touching the debounce lock at all.
        // The overwhelming majority of saves application-wide are not a
        // declared dependency of anything (including a model's own first
        // save, before anything could possibly depend on it yet) — if an
        // id with zero current dependents consumed the debounce window
        // anyway, it would block a *later, real* invalidation for the
        // rest of that window for no reason.
        $idsWithDependents = $this->idsWithDependents($dependencyType, $ids);

        if ($idsWithDependents === []) {
            return;
        }

        $lockedIds = $this->acquireDebounceLocks($dependencyType, $idsWithDependents);

        if ($lockedIds === []) {
            return;
        }

        $pairs = $this->resolveAffectedPairs($dependencyType, $lockedIds);

        if ($pairs === []) {
            return;
        }

        $batchSize = max(1, (int) config('eloquent-rag.queue.batch_size', 500));

        foreach (array_chunk($pairs, $batchSize) as $batch) {
            SyncRagDocument::dispatch($batch)->afterCommit();
        }
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int|string> Ids for which this call won the debounce lock.
     */
    private function acquireDebounceLocks(string $dependencyType, array $ids): array
    {
        $debounceSeconds = (int) config('eloquent-rag.invalidation.debounce_seconds', 5);
        $locked = [];

        foreach ($ids as $id) {
            $key = "eloquent-rag:invalidate:{$dependencyType}:{$id}";

            if (Cache::add($key, true, $debounceSeconds)) {
                $locked[] = $id;
            }
        }

        return $locked;
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int|string>
     */
    private function idsWithDependents(string $dependencyType, array $ids): array
    {
        return DB::table('rag_dependencies')
            ->where('dependency_type', $dependencyType)
            ->whereIn('dependency_id', $ids)
            ->distinct()
            ->pluck('dependency_id')
            ->all();
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<array{model_type: string, model_id: int|string}>
     */
    private function resolveAffectedPairs(string $dependencyType, array $ids): array
    {
        return DB::table('rag_dependencies')
            ->join('rag_documents', 'rag_documents.id', '=', 'rag_dependencies.document_id')
            ->where('rag_dependencies.dependency_type', $dependencyType)
            ->whereIn('rag_dependencies.dependency_id', $ids)
            ->distinct()
            ->get(['rag_documents.model_type', 'rag_documents.model_id'])
            ->map(fn (object $row): array => [
                'model_type' => $row->model_type,
                'model_id' => $row->model_id,
            ])
            ->all();
    }
}
