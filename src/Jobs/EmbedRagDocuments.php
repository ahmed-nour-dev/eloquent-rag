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
 * The auto-embed half of config('eloquent-rag.embedding.auto') (issue
 * #64): SyncRagDocument queues this for the documents its sync() pass
 * actually changed, so they become searchable without a manual rag:sync.
 *
 * A separate job rather than embedding inline in SyncRagDocument: embed()
 * calls an external provider (latency, cost, rate limits), and keeping it
 * out of the sync batch keeps sync jobs cheap and gives embedding its own,
 * smaller batches (config('eloquent-rag.embedding.auto_batch_size')) and
 * its own timeout.
 *
 * Per-pair failure isolation mirrors SyncRagDocument: one document's
 * embed() throwing (a provider error, InvalidEmbeddingResponse, an
 * unsupported backend) is recorded on that document row as status
 * 'failed' — where rag:doctor already looks — and never aborts or retries
 * the rest of the batch. $tries/$backoff guard the batch as a whole
 * against infrastructure failures outside that loop, and are safe to
 * repeat: embed() only fills chunks whose embedding is still NULL.
 */
final class EmbedRagDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 300;

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

            if ($model === null) {
                continue;
            }

            try {
                $model->rag()->embed();
            } catch (Throwable $e) {
                $this->recordFailure($model, $e);
            }
        }
    }

    private function recordFailure(Model $model, Throwable $e): void
    {
        DB::connection(RagConnectionResolver::resolve($model))->table('rag_documents')
            ->where('model_type', $model::class)
            ->where('model_id', $model->getKey())
            ->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
    }
}
