<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
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
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            // No native vector column type on this driver. The Phase 1
            // nullable text column stays as-is — VectorBackendCapability
            // rejects this connection before anything tries to embed or
            // search against it, per ADR-0003.
            return;
        }

        Schema::table('rag_chunks', function (Blueprint $table) {
            $table->vector('embedding', config('eloquent-rag.embedding.dimensions'))
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        Schema::table('rag_chunks', function (Blueprint $table) {
            $table->text('embedding')->nullable()->change();
        });
    }
};
