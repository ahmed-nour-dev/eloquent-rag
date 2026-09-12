<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schema scaffolding only. The `embedding` column is a Phase 1 placeholder
 * (see the create_rag_chunks_table migration) — nothing writes to it until
 * Phase 3 wires up Laravel AI and a supported vector backend.
 */
class RagChunk extends Model
{
    protected $table = 'rag_chunks';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(RagDocument::class, 'document_id');
    }
}
