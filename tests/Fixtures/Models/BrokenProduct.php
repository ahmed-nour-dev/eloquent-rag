<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Tests\Fixtures\Models;

use Ahmednour\EloquentRag\Rag;
use Ahmednour\EloquentRag\RagDefinition;

/**
 * Test-only fixture for the SyncRagDocument batch-isolation regression
 * (issue #54): same underlying table as Product, but its
 * toRagDefinition() declares a mistyped relation path, so sync() always
 * throws InvalidRelationPath — exactly the "renamed/mistyped relation"
 * scenario the issue describes. Used to put one genuinely-throwing pair
 * next to a healthy Product pair in the same SyncRagDocument batch.
 */
class BrokenProduct extends Product
{
    protected $table = 'products';

    public function toRagDefinition(): RagDefinition
    {
        return Rag::make()
            ->content(['name'])
            ->relation('categroy.name'); // typo, real relation is "category"
    }
}
