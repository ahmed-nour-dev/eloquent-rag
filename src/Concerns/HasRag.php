<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Concerns;

use Ahmednour\EloquentRag\RagDefinition;
use Ahmednour\EloquentRag\RagSynchronizer;

/**
 * Wires a model into the RAG sync lifecycle: created/updated/restored queue
 * a re-sync (after commit), deleted forgets the document (chunks and
 * dependencies cascade via FK). See ADR-0006 for the transaction/queue
 * boundary this relies on.
 */
trait HasRag
{
    /**
     * Declares how this model's document is rendered and what it depends
     * on (see ADR-0002 — declarative only, no relationship discovery).
     *
     * Named `toRagDefinition()` rather than the build plan's `rag()`
     * wording so it stays distinct from the runtime entry point below:
     * `rag()` returns a synchronizer bound to this definition, it is not
     * the definition itself.
     */
    abstract public function toRagDefinition(): RagDefinition;

    public function rag(): RagSynchronizer
    {
        return new RagSynchronizer($this, $this->toRagDefinition());
    }

    /**
     * Manually re-syncs this model's dependency rows. Required after any
     * belongsToMany attach()/detach()/sync() call on a declared relation
     * dependency — per ADR-0007, Eloquent fires no pivot events, so there
     * is nothing for the package to observe automatically.
     */
    public function resyncRag(): void
    {
        $this->rag()->sync();
    }

    protected static function bootHasRag(): void
    {
        static::created(fn (self $model) => $model->rag()->queue());
        static::updated(fn (self $model) => $model->rag()->queue());
        static::deleted(fn (self $model) => $model->rag()->forget());

        // `restored` has no dedicated static convenience method on the base
        // Model — only SoftDeletes defines one — so it's registered via the
        // underlying mechanism directly. This is harmless and simply never
        // fires on a model that doesn't use SoftDeletes.
        static::registerModelEvent('restored', fn (self $model) => $model->rag()->queue());
    }
}
