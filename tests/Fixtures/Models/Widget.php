<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Tests\Fixtures\Models;

use Ahmednour\EloquentRag\Concerns\HasRag;
use Ahmednour\EloquentRag\Rag;
use Ahmednour\EloquentRag\RagDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only fixture for issue #30 (custom Eloquent database connections):
 * a minimal HasRag model pinned to a non-default connection, so the
 * CustomConnectionTest suite can prove its RAG data follows it there.
 *
 * @property string $name
 */
class Widget extends Model
{
    use HasRag;

    protected $connection = 'secondary';

    protected $guarded = [];

    public function toRagDefinition(): RagDefinition
    {
        return Rag::make()->content(['name']);
    }
}
