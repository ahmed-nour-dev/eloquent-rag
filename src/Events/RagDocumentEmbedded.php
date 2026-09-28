<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Events;

use Ahmednour\EloquentRag\Models\RagDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after RagSynchronizer::embed() wrote at least one chunk embedding
 * and the document ended up fully embedded (status 'synced') — i.e. it is
 * now searchable with its current content. NOT fired by an embed() call
 * that had nothing to do, or one whose writes were superseded by a
 * concurrent sync() and left the document still 'pending'.
 */
final class RagDocumentEmbedded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly RagDocument $document,
        public readonly int $embeddedChunks,
    ) {}
}
