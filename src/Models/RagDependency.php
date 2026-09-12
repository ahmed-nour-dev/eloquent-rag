<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schema scaffolding only. Population of this table happens during sync
 * (Phase 2); this model exists now so Phase 1's schema is queryable/testable.
 */
class RagDependency extends Model
{
    protected $table = 'rag_dependencies';

    protected $guarded = [];

    public function document(): BelongsTo
    {
        return $this->belongsTo(RagDocument::class, 'document_id');
    }
}
