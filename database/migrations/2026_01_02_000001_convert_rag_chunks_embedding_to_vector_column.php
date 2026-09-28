<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: upgrades the Phase 1 nullable-text `embedding` placeholder to
 * Laravel's native vector column type — but only on a driver that has
 * one. VectorBackendCapability::ensureSupported() is the real gate on
 * whether a connection is actually usable (real MariaDB 11.7+ / pgvector
 * version check, not just "does this driver exist"); this migration only
 * needs to avoid calling $table->vector(...) on a driver whose schema
 * grammar has no typeVector() at all (SQLite, sqlsrv, plain unconfigured
 * mysql), which would fail outright rather than degrade gracefully.
 *
 * A real MySQL server (the 'mysql' driver NOT connected to MariaDB) is
 * skipped too: MySQL 8.x has no VECTOR type at all, so converting would
 * fail the whole migration run, and on MySQL 9.x a VECTOR column would
 * reject the JSON text AsVector writes there. Leaving the text column in
 * place is what lets the opt-in portable fallback serve plain MySQL — see
 * VectorBackendCapability::isPortableFallbackCandidate().
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! $this->hasNativeVectorColumn()) {
            // No native vector column type on this driver. The Phase 1
            // nullable text column stays as-is — VectorBackendCapability
            // rejects this connection before anything tries to embed or
            // search against it, per ADR-0003, unless the opt-in portable
            // fallback is enabled (which stores JSON in this column).
            return;
        }

        $dimensions = config('eloquent-rag.embedding.dimensions');

        Schema::table('rag_chunks', function (Blueprint $table) use ($driver, $dimensions) {
            $column = $table->vector('embedding', $dimensions)->nullable();

            if ($driver === 'pgsql') {
                // Postgres refuses to auto-cast text -> vector; it has to be told how.
                $column->using("embedding::vector({$dimensions})");
            }

            $column->change();
        });
    }

    public function down(): void
    {
        if (! $this->hasNativeVectorColumn()) {
            return;
        }

        Schema::table('rag_chunks', function (Blueprint $table) {
            $table->text('embedding')->nullable()->change();
        });
    }

    private function hasNativeVectorColumn(): bool
    {
        $connection = Schema::getConnection();

        return match ($connection->getDriverName()) {
            'mariadb', 'pgsql' => true,
            'mysql' => $connection instanceof MySqlConnection && $connection->isMaria(),
            default => false,
        };
    }
};
