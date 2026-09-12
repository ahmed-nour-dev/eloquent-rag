<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Schema scaffolding only. Lifecycle wiring (observers, sync, invalidation)
 * is Phase 2 scope.
 */
class RagDocument extends Model
{
    protected $table = 'rag_documents';

    protected $guarded = [];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(RagChunk::class, 'document_id');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(RagDependency::class, 'document_id');
    }
}
