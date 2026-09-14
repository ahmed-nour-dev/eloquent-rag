<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves which database connection the package's own tables
 * (rag_documents/rag_chunks/rag_dependencies) use for a given indexed
 * model — see docs/installation.md#custom-database-connections.
 *
 * Resolves by connection *name*, not a snapshotted connection instance, so
 * this stays correct under multi-tenancy packages that swap what database a
 * fixed connection name points to per request/job (e.g. stancl/tenancy).
 */
final class RagConnectionResolver
{
    /**
     * config('eloquent-rag.connection'), when set, centralizes every
     * indexed model's RAG data onto one connection regardless of that
     * model's own connection. Otherwise the indexed model's own
     * getConnectionName() is mirrored — null for a model with no custom
     * connection, which is Laravel's own "use the default connection"
     * signal, so single-connection apps see no behavior change.
     *
     * @param  Model|class-string<Model>  $model
     */
    public static function resolve(Model|string $model): ?string
    {
        $configured = config('eloquent-rag.connection');

        if ($configured !== null) {
            return $configured;
        }

        $instance = is_string($model) ? new $model : $model;

        return $instance->getConnectionName();
    }
}
