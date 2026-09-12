<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schema scaffolding only. The `embedding` column is a Phase 1 placeholder
 * (see the create_rag_chunks_table migration) — nothing writes to it until
 * Phase 3 wires up Laravel AI and a supported vector backend.
 *
 * @property int $id
 * @property int $document_id
 * @property int $chunk_index
 * @property string $content_hash
 * @property string|null $embedding
 * @property array<string, mixed>|null $metadata
 */
class RagChunk extends Model
{
    protected $table = 'rag_chunks';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * @return BelongsTo<RagDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(RagDocument::class, 'document_id');
    }
}
