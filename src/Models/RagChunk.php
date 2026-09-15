<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Models;

use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schema scaffolding for Phases 1-2; Phase 3 wires up real writes. The
 * `embedding` column is a real vector column on MariaDB/Postgres (see the
 * convert_rag_chunks_embedding_to_vector_column migration) and stays a
 * plain nullable text column elsewhere (e.g. SQLite in this package's own
 * tests). AsVector's cast degrades correctly either way — on a
 * MariaDbGrammar connection it reads/writes the binary vector encoding, on
 * anything else it JSON-encodes/decodes the float array against the plain
 * column, so this cast is applied universally.
 *
 * @property int $id
 * @property int $document_id
 * @property int $chunk_index
 * @property string $content_hash
 * @property array<int, float>|null $embedding
 * @property string|null $embedding_provider
 * @property string|null $embedding_model
 * @property int|null $embedding_dimensions
 * @property string|null $embedding_hash
 * @property array<string, mixed>|null $metadata
 */
class RagChunk extends Model
{
    protected $table = 'rag_chunks';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'embedding' => AsVector::class,
    ];

    /**
     * @return BelongsTo<RagDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(RagDocument::class, 'document_id');
    }
}
