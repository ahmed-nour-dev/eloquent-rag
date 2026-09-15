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
 *
 * Retry/timeout policy (issue #55): a delete-by-key batch has the same
 * infrastructure-failure profile as SyncRagDocument (see its docblock) but
 * is lighter per pair, hence the smaller $timeout. It's just as safe to
 * retry — `?->delete()` on a pair a prior attempt already removed is a
 * no-op, not an error — so the same bounded-retry-with-backoff reasoning
 * applies, and the same ShouldBeUnique decision: not implemented, so a
 * forget dispatched for a since-recreated model can't be silently dropped
 * by a queue-level dedup key colliding with an unrelated, still-pending
 * dispatch for that same (model_type, model_id) pair.
 */
final class ForgetRagDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 60;

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
