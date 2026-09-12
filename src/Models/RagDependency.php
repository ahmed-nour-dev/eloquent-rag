<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Population of this table happens during RagSynchronizer::sync().
 *
 * @property int $id
 * @property int $document_id
 * @property string $dependency_type
 * @property int|string $dependency_id
 */
class RagDependency extends Model
{
    protected $table = 'rag_dependencies';

    protected $guarded = [];

    /**
     * @return BelongsTo<RagDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(RagDocument::class, 'document_id');
    }
}
