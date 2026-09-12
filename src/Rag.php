<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

final class Rag
{
    public static function make(): RagDefinition
    {
        return RagDefinition::make();
    }
}
