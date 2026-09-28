<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Events;

use Ahmednour\EloquentRag\Models\RagDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after RagSynchronizer::sync() actually reconciled a document —
 * its content or configuration changed (or $forced, via rag:rebuild) and
 * the document, chunk, and dependency rows were rewritten. NOT fired when
 * sync() short-circuits on an unchanged hash pair, so listeners see real
 * changes only. Deferred until the enclosing transaction commits, if any.
 *
 * After this event the document is structurally current but its changed
 * chunks have no embeddings yet (status 'pending') — see
 * RagDocumentEmbedded for when it becomes searchable.
 */
final class RagDocumentSynced implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly RagDocument $document,
        public readonly bool $forced = false,
    ) {}
}
