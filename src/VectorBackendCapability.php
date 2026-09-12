<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the connection actually meets ADR-0003's backend requirements
 * (MariaDB 11.7+ or PostgreSQL+pgvector) before any embedding/search work
 * touches it.
 *
 * Deliberately does NOT rely solely on Illuminate\Database\Query\Grammars\
 * Grammar::supportsVectorDistance() — both MariaDbGrammar and PostgresGrammar
 * override that to return true unconditionally, for ANY MariaDB version
 * (even pre-11.7, which lacks the VECTOR column type / vec_distance_cosine())
 * and ANY Postgres (even without the pgvector extension installed). Trusting
 * that flag alone would let an unsupported backend pass config-time checks
 * and fail later with an obscure SQL error — exactly what Phase 4's
 * rag:doctor (and this check) exists to prevent. So this class queries the
 * real server version / extension list instead.
 */
final class VectorBackendCapability
{
    public const MARIADB_FLOOR = '11.7.0';

    /**
     * @throws UnsupportedVectorBackend
     */
    public static function ensureSupported(?string $connectionName = null): void
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        match ($driver) {
            'mysql', 'mariadb' => self::ensureMariaDbSupported($connection),
            'pgsql' => self::ensurePostgresSupported($connection),
            default => throw UnsupportedVectorBackend::unsupportedDriver($driver),
        };
    }

    private static function ensureMariaDbSupported(Connection $connection): void
    {
        // Both the 'mysql' driver (connected to an actual MariaDB server,
        // a common real-world config) and the 'mariadb' driver land here.
        // isMaria() is what tells them apart from a real MySQL 8.x server,
        // which ADR-0003 explicitly excludes.
        $isMaria = $connection instanceof MySqlConnection && $connection->isMaria();

        if (! $isMaria) {
            throw UnsupportedVectorBackend::unsupportedDriver('mysql');
        }

        // getServerVersion() already strips MariaDB's historical
        // "5.5.5-11.7.2-MariaDB" compatibility-version wrapper down to a
        // clean "11.7.2" — see Illuminate\Database\MariaDbConnection /
        // MySqlConnection::getServerVersion().
        $version = $connection->getServerVersion();

        if (! self::meetsMariaDbFloor($version)) {
            throw UnsupportedVectorBackend::mariaDbBelowFloor($version);
        }
    }

    private static function ensurePostgresSupported(Connection $connection): void
    {
        if (! self::pgvectorExtensionInstalled($connection)) {
            throw UnsupportedVectorBackend::pgvectorExtensionMissing();
        }
    }

    /**
     * Pure comparison against ADR-0003's floor — kept separate from the
     * connection query above so it can be unit tested with literal version
     * strings, no database required.
     */
    public static function meetsMariaDbFloor(string $cleanVersion): bool
    {
        return version_compare($cleanVersion, self::MARIADB_FLOOR, '>=');
    }

    private static function pgvectorExtensionInstalled(Connection $connection): bool
    {
        $row = $connection->selectOne(
            "select extversion from pg_extension where extname = 'vector'"
        );

        return self::extensionRowIndicatesInstalled($row);
    }

    /**
     * Pure predicate over a query result row — kept separate so the
     * "what counts as installed" logic is unit testable without a real
     * Postgres connection (fed a fixture row/null directly).
     */
    public static function extensionRowIndicatesInstalled(?object $row): bool
    {
        return $row !== null;
    }

    /**
     * Extracts the declared dimension count from a column type description,
     * e.g. MariaDB's `information_schema.columns.COLUMN_TYPE` ("vector(1536)")
     * or Postgres's `format_type(atttypid, atttypmod)` output (also
     * "vector(1536)" — pgvector uses the same textual shape there). Used by
     * rag:doctor's dimension-mismatch check. Kept pure/testable with
     * literal fixture strings — the actual information_schema/pg_attribute
     * query that produces this string lives in the command itself and
     * cannot be exercised against a real MariaDB 11.7+/pgvector server in
     * this package's own SQLite-based test suite.
     */
    public static function parseVectorDimensions(?string $typeDescription): ?int
    {
        if ($typeDescription === null) {
            return null;
        }

        if (preg_match('/vector\((\d+)\)/i', $typeDescription, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
