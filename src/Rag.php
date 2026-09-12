<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

final class Rag
{
    public static function make(): RagDefinition
    {
        return RagDefinition::make();
    }

    /**
     * Escape hatch for write paths that bypass Eloquent events entirely —
     * mass updates (`Category::where(...)->update()`) and bulk inserts —
     * per ADR-0006's documented limitation. Call this manually wherever
     * such a path changes something declared as a dependency.
     *
     * @param  int|string|array<int, int|string>  $dependencyIds
     */
    public static function invalidate(string $dependencyType, int|string|array $dependencyIds): void
    {
        app(DependencyInvalidator::class)->invalidate($dependencyType, $dependencyIds);
    }
}
