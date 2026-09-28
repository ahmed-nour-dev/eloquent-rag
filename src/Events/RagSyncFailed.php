<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

/**
 * Fired whenever RagSynchronizer::sync() throws — from the queued lifecycle
 * job, dependency fan-out, rag:sync/rag:rebuild, or a direct call — just
 * before the exception propagates to the caller (which, for the queued job
 * and the CLI, records it on the document row as status 'failed').
 */
final class RagSyncFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly Throwable $exception,
    ) {}
}
