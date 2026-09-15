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
 *
 * Retry/timeout policy (issue #55): every per-pair failure that sync()
 * itself can produce is already caught and recorded above — it never
 * reaches Laravel's retry machinery. What $tries/$backoff below actually
 * guard against is the batch failing as a whole *outside* that loop (a
 * dropped DB connection, a deadlock) before or between pairs. That's an
 * infrastructure hiccup, not a bad document, so a few retries with a short
 * growing delay are worth it — and safe to repeat, since sync() re-checks
 * each pair's content/configuration hash and no-ops on ones a prior,
 * partially-completed attempt already finished. $timeout bounds a single
 * attempt at this job's largest batch (config('eloquent-rag.queue.batch_size'),
 * default 500) well above its normal cost, so a genuinely stuck job is
 * killed and retried rather than parking a worker on it indefinitely.
 *
 * Deliberately NOT ShouldBeUnique: this job's dedup story is handled
 * upstream, at resolution time, by ADR-0005's debounce lock — not by the
 * queue. A queue-native unique-job constraint operates on *dispatch*, so it
 * would risk dropping a legitimately newer batch (e.g. RagSynchronizer::
 * queue()'s single-model dispatch landing right after a fan-out batch
 * already queued the same model) just because an older, still-pending
 * batch for an overlapping key hasn't run yet — exactly the
 * supersede-not-drop hazard called out in #55. sync()'s own idempotency
 * already makes any genuine duplicate dispatch wasteful, never incorrect.
 */
final class SyncRagDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 120;

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
