<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $model_type
 * @property int|string $model_id
 * @property int $version
 * @property string $content_hash
 * @property string $configuration_hash
 * @property string $status
 * @property Carbon|null $synced_at
 */
class RagDocument extends Model
{
    protected $table = 'rag_documents';

    protected $guarded = [];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    /**
     * @return HasMany<RagChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(RagChunk::class, 'document_id');
    }

    /**
     * @return HasMany<RagDependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(RagDependency::class, 'document_id');
    }
}
