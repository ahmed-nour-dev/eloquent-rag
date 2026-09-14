<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by RelationPathValidator when a declared ->relation() path doesn't
 * describe a real chain of Eloquent relationships. ADR-0002 promises that
 * an invalid declared path is "a loud, obvious failure" rather than a
 * silently-empty dependency set — this is what makes that promise true.
 */
final class InvalidRelationPath extends InvalidArgumentException
{
    public static function missingRelationSegment(string $modelClass, string $path): self
    {
        return new self(sprintf(
            "Invalid relation path '%s' declared on [%s]: ->relation() paths must reference a related model's ".
            "attribute (e.g. 'category.name'), not one of the model's own attributes — use ->content(['%s']) for that instead.",
            $path, $modelClass, $path,
        ));
    }

    public static function unknownRelation(string $modelClass, string $currentModelClass, string $path, string $segment): self
    {
        return new self(sprintf(
            "Invalid relation path '%s' declared on [%s]: '%s' is not a method on [%s]. ".
            'Every segment but the last in a ->relation() path must name a real Eloquent relationship method '
            .'(see ADR-0002, docs/adr/0002-declarative-dependency-registry.md).',
            $path, $modelClass, $segment, $currentModelClass,
        ));
    }

    public static function notARelation(string $modelClass, string $currentModelClass, string $path, string $segment): self
    {
        return new self(sprintf(
            "Invalid relation path '%s' declared on [%s]: [%s::%s()] exists but does not return an Eloquent relationship. ".
            'Every segment but the last in a ->relation() path must name a real Eloquent relationship method '
            .'(see ADR-0002, docs/adr/0002-declarative-dependency-registry.md).',
            $path, $modelClass, $currentModelClass, $segment,
        ));
    }
}
