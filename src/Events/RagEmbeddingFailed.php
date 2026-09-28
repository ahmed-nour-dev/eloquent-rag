<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

/**
 * Fired whenever RagSynchronizer::embed() throws — an unsupported vector
 * backend, a provider error, or an InvalidEmbeddingResponse — from any
 * caller (the auto-embed job, rag:sync/rag:rebuild, or a direct call),
 * just before the exception propagates.
 */
final class RagEmbeddingFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly Throwable $exception,
    ) {}
}
