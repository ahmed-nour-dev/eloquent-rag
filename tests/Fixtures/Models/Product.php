<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Tests\Fixtures\Models;

use Ahmednour\EloquentRag\Concerns\HasRag;
use Ahmednour\EloquentRag\Rag;
use Ahmednour\EloquentRag\RagDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use HasRag;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class);
    }

    public function toRagDefinition(): RagDefinition
    {
        return Rag::make()
            ->content(['name', 'sku', 'price'])
            ->relation('category.name')
            ->relation('brand.name')
            ->relation('features.name');
    }
}
