<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Support;

use Ahmednour\EloquentRag\Exceptions\InvalidRelationPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Validates that a declared ->relation() path names a real chain of
 * Eloquent relationship methods, per ADR-0002's promise that an invalid
 * declared path fails loudly rather than silently resolving to an empty
 * dependency set (see RagSynchronizer::reconcileDependencies()'s use of
 * this, and docs/adr/0002-declarative-dependency-registry.md).
 *
 * Only the segments before the last are checked — the last segment is the
 * leaf attribute being rendered, not itself a relation, and attributes are
 * dynamic enough (accessors, casts) that there is nothing static to check.
 *
 * Each relation method is called directly (never through the model's
 * magic __get, which would trigger a real query to lazy-load the
 * relation) so validation never touches the database: relation builder
 * methods like belongsTo()/hasMany() just construct a Relation object,
 * and Relation::getRelated() returns a fresh, unpersisted instance of the
 * related model to validate the next segment against.
 */
final class RelationPathValidator
{
    public static function validate(Model $model, string $path): void
    {
        $segments = explode('.', $path);

        if (count($segments) < 2) {
            throw InvalidRelationPath::missingRelationSegment($model::class, $path);
        }

        array_pop($segments);

        $current = $model;

        foreach ($segments as $segment) {
            // method_exists(), not is_callable() or a direct call: calling
            // an undeclared method on an Eloquent model falls through to
            // __call(), which forwards to the query builder and could
            // execute an entirely unrelated query (e.g. a path segment
            // that happens to match a builder method like 'count').
            if (! method_exists($current, $segment)) {
                throw InvalidRelationPath::unknownRelation($model::class, $current::class, $path, $segment);
            }

            $relation = $current->{$segment}();

            if (! $relation instanceof Relation) {
                throw InvalidRelationPath::notARelation($model::class, $current::class, $path, $segment);
            }

            $current = $relation->getRelated();
        }
    }
}
