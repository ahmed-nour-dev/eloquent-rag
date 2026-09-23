<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Exceptions;

use RuntimeException;

/**
 * Thrown by VectorBackendCapability::ensureSupported() — see ADR-0003.
 * Deliberately split into named constructors so the message is always
 * specific about which requirement failed, rather than a single generic
 * "backend unsupported" string.
 *
 * $fallbackEligible distinguishes a driver with no native vector backend
 * at all (SQLite, plain MySQL — unsupportedDriver(), eligible for the
 * ADR-0011 opt-in PHP-side fallback) from a genuinely misconfigured
 * *supported* backend (old MariaDB, Postgres missing pgvector) that
 * should be fixed, not silently routed around — see
 * VectorBackendCapability::ensureUsable().
 */
final class UnsupportedVectorBackend extends RuntimeException
{
    private function __construct(string $message, public readonly bool $fallbackEligible)
    {
        parent::__construct($message);
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self(sprintf(
            'The [%s] database driver has no native vector support. Eloquent RAG requires MariaDB 11.7+ or PostgreSQL with the pgvector extension for production vector search — see ADR-0003 (docs/adr/0003-backend-support-matrix.md). For local development or small-scale use, opt into the PHP-side fallback via config(\'eloquent-rag.fallback.enabled\') = true — see docs/backend-support.md#fallback-backend and ADR-0011 (docs/adr/0011-portable-fallback-backend.md). That fallback is not recommended for production.',
            $driver,
        ), fallbackEligible: true);
    }

    public static function mariaDbBelowFloor(string $actualVersion): self
    {
        return new self(sprintf(
            'Connected to MariaDB %s, but Eloquent RAG requires MariaDB 11.7+ for native VECTOR column and vec_distance_cosine() support (see ADR-0003). Laravel\'s own grammar reports vector support for any MariaDB version, which is not sufficient — upgrade the server before configuring this package.',
            $actualVersion,
        ), fallbackEligible: false);
    }

    public static function pgvectorExtensionMissing(): self
    {
        return new self(
            'Connected to PostgreSQL, but the pgvector extension is not installed on this database (no matching row in pg_extension). '.
            'Run `CREATE EXTENSION IF NOT EXISTS vector;` on the database, or see ADR-0003 (docs/adr/0003-backend-support-matrix.md).',
            fallbackEligible: false,
        );
    }
}
