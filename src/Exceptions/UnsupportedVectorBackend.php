<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Exceptions;

use RuntimeException;

/**
 * Thrown by VectorBackendCapability::ensureSupported() — see ADR-0003.
 * Deliberately split into named constructors so the message is always
 * specific about which requirement failed, rather than a single generic
 * "backend unsupported" string.
 */
final class UnsupportedVectorBackend extends RuntimeException
{
    public static function unsupportedDriver(string $driver): self
    {
        return new self(sprintf(
            'The [%s] database driver has no native vector support. Eloquent RAG requires MariaDB 11.7+ or PostgreSQL with the pgvector extension — see ADR-0003 (docs/adr/0003-backend-support-matrix.md). Plain MySQL is explicitly unsupported: there is no PHP-side cosine-similarity fallback.',
            $driver,
        ));
    }

    public static function mariaDbBelowFloor(string $actualVersion): self
    {
        return new self(sprintf(
            'Connected to MariaDB %s, but Eloquent RAG requires MariaDB 11.7+ for native VECTOR column and vec_distance_cosine() support (see ADR-0003). Laravel\'s own grammar reports vector support for any MariaDB version, which is not sufficient — upgrade the server before configuring this package.',
            $actualVersion,
        ));
    }

    public static function pgvectorExtensionMissing(): self
    {
        return new self(
            'Connected to PostgreSQL, but the pgvector extension is not installed on this database (no matching row in pg_extension). '.
            'Run `CREATE EXTENSION IF NOT EXISTS vector;` on the database, or see ADR-0003 (docs/adr/0003-backend-support-matrix.md).'
        );
    }
}
