<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The single dispatch mechanism for both a model's own queued sync
 * (RagSynchronizer::queue(), a batch of one) and dependency fan-out
 * (DependencyInvalidator, a batch of up to config('eloquent-rag.queue.batch_size')).
 * There is exactly one place that renders/hashes/chunks/reconciles a
 * document — RagSynchronizer::sync(), called per pair below.
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

            $model->rag()->sync();
        }
    }
}
