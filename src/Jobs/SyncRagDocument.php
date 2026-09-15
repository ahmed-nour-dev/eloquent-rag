<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Jobs;

use Ahmednour\EloquentRag\Support\RagConnectionResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single dispatch mechanism for both a model's own queued sync
 * (RagSynchronizer::queue(), a batch of one) and dependency fan-out
 * (DependencyInvalidator, a batch of up to config('eloquent-rag.queue.batch_size')).
 * There is exactly one place that renders/hashes/chunks/reconciles a
 * document — RagSynchronizer::sync(), called per pair below.
 *
 * Each pair gets its own try/catch, mirroring
 * ProcessesRagModels::processOne(): one pair's sync() throwing (a renamed
 * relation, a custom toRagDefinition() bug, ...) must not abort the rest of
 * the batch — up to config('eloquent-rag.queue.batch_size') unrelated
 * pairs — or send already-succeeded pairs back through on Laravel's retry.
 * The failure is recorded onto that document row instead, the same place
 * rag:doctor already looks for sync failures.
 */
final class SyncRagDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<array{model_type: string, model_id: int|string}>  $pairs
     */
    public function __construct(
        public readonly array $pairs,
    ) {}

    public function handle(): void
    {
        foreach ($this->pairs as $pair) {
            $model = $pair['model_type']::find($pair['model_id']);

            // Concurrently deleted between resolution and processing —
            // nothing to sync.
            if ($model === null) {
                continue;
            }

            try {
                $model->rag()->sync();
            } catch (Throwable $e) {
                $this->recordFailure($model, $e);
            }
        }
    }

    /**
     * Records the failure on the model's document row if one exists. If
     * sync() itself failed before ever creating a document (e.g. rendering
     * threw on the very first sync), there is nothing to attach the error
     * to — update() simply affects zero rows in that case, mirroring
     * ProcessesRagModels::recordFailure() rather than forcing a row into
     * existence just to hold an error.
     */
    private function recordFailure(Model $model, Throwable $e): void
    {
        DB::connection(RagConnectionResolver::resolve($model))->table('rag_documents')
            ->where('model_type', $model::class)
            ->where('model_id', $model->getKey())
            ->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
    }
}
