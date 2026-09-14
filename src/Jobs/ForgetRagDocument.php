<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Jobs;

use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Support\RagConnectionResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after the enclosing transaction commits (ADR-0006) by
 * RagSynchronizer::queueForget(), the same after-commit protection
 * SyncRagDocument gives create/update/restore. By the time this runs the
 * underlying model row is already gone, so — like rag:forget
 * (RagForgetCommand) — it deletes by (model_type, model_id) directly
 * against rag_documents rather than trying to re-resolve a model instance.
 */
final class ForgetRagDocument implements ShouldQueue
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
            // The source model is already gone, so there's no instance to
            // resolve a connection from — RagConnectionResolver accepts the
            // model_type class string directly instead.
            RagDocument::on(RagConnectionResolver::resolve($pair['model_type']))
                ->where('model_type', $pair['model_type'])
                ->where('model_id', $pair['model_id'])
                ->first()
                ?->delete();
        }
    }
}
