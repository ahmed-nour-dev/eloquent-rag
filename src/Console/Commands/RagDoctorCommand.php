<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Console\Commands;

use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Ahmednour\EloquentRag\VectorBackendCapability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Rag doctor first because it's cheap and it's what makes the package
 * trustworthy on first install." — the build plan, Phase 4. Every check
 * here is meant to turn an obscure SQL error four queue jobs deep into a
 * clear, actionable line before a single row gets indexed.
 *
 * FAIL-level checks (Laravel version, missing vector support, dimension
 * mismatch) drive a non-zero exit code, so this is safe to wire into CI or
 * a deploy step. WARN-level checks (sync queue, orphaned rows, failed
 * documents, failed jobs) are operational hygiene signals, not blockers.
 */
class RagDoctorCommand extends Command
{
    protected $signature = 'rag:doctor
        {--connection= : Database connection to check (default: the app\'s default connection)}';

    protected $description = 'Check this application\'s configuration for known Eloquent RAG problems before they surface mid-queue';

    private ?string $connection = null;

    public function handle(): int
    {
        $this->connection = $this->option('connection');

        $hasFailure = false;

        if (! $this->checkLaravelVersion()) {
            $hasFailure = true;
        }

        $backendSupported = $this->checkVectorBackend();

        if (! $backendSupported) {
            $hasFailure = true;
        }

        if ($backendSupported) {
            if (! $this->checkDimensionMatch()) {
                $hasFailure = true;
            }

            $this->checkVectorIndex();
        } else {
            $this->line('  (skipping dimension and vector index checks — no supported vector backend)');
        }

        $this->checkQueueDriver();
        $this->checkOrphanedDependencies();
        $this->checkFailedDocuments();
        $this->checkFailedJobs();

        if ($hasFailure) {
            $this->newLine();
            $this->error('rag:doctor found problems that must be fixed before this package is safe to use.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('rag:doctor found no blocking problems.');

        return self::SUCCESS;
    }

    private function checkLaravelVersion(): bool
    {
        $version = app()->version();
        $meetsFloor = version_compare($version, '13.29.0', '>=');

        if ($meetsFloor) {
            $this->info("[PASS] Laravel {$version} meets the 13.29+ floor required for the native vector query builder (ADR-0003).");

            return true;
        }

        $this->error("[FAIL] Laravel {$version} is below the 13.29 floor required for the native vector query builder (ADR-0003). Upgrade Laravel before configuring this package.");

        return false;
    }

    private function checkVectorBackend(): bool
    {
        try {
            VectorBackendCapability::ensureSupported($this->connection);
        } catch (UnsupportedVectorBackend $e) {
            $this->error("[FAIL] {$e->getMessage()}");

            return false;
        }

        $this->info('[PASS] Database connection is a supported vector backend (MariaDB 11.7+ or PostgreSQL+pgvector).');

        return true;
    }

    private function checkDimensionMatch(): bool
    {
        $configured = (int) config('eloquent-rag.embedding.dimensions');
        $actual = $this->actualEmbeddingColumnDimensions();

        if ($actual === null) {
            $this->warn('[WARN] Could not determine the declared dimension of rag_chunks.embedding on this connection — skipping the mismatch check.');

            return true;
        }

        if ($actual === $configured) {
            $this->info("[PASS] rag_chunks.embedding is declared as vector({$actual}), matching config('eloquent-rag.embedding.dimensions').");

            return true;
        }

        $this->error("[FAIL] rag_chunks.embedding is declared as vector({$actual}), but config('eloquent-rag.embedding.dimensions') is {$configured}. Run rag:rebuild after fixing the mismatch (changing the column size on a table with existing rows requires care — back up first).");

        return false;
    }

    /**
     * Driver-specific introspection of the real declared vector column
     * size. This glue cannot be exercised against a real MariaDB
     * 11.7+/pgvector server in this package's own SQLite-based test suite
     * — only VectorBackendCapability::parseVectorDimensions() (the pure
     * parsing half) is unit tested with literal fixture strings.
     */
    private function actualEmbeddingColumnDimensions(): ?int
    {
        $connection = DB::connection($this->connection);
        $driver = $connection->getDriverName();

        $typeDescription = match ($driver) {
            'mysql', 'mariadb' => $connection->selectOne(
                'select COLUMN_TYPE as type from information_schema.columns '.
                'where table_schema = database() and table_name = ? and column_name = ?',
                ['rag_chunks', 'embedding'],
            )?->type,
            'pgsql' => $connection->selectOne(
                'select format_type(a.atttypid, a.atttypmod) as type '.
                'from pg_attribute a join pg_class c on a.attrelid = c.oid '.
                'where c.relname = ? and a.attname = ? and a.attnum > 0 and not a.attisdropped',
                ['rag_chunks', 'embedding'],
            )?->type,
            default => null,
        };

        return VectorBackendCapability::parseVectorDimensions($typeDescription);
    }

    /**
     * WARN-level, not FAIL: an unindexed vector column is a performance
     * problem, not a correctness one (see ADR-0008). pgvector gets a real
     * index automatically (migration
     * 2026_01_04_000001_add_vector_index_to_rag_chunks_table); MariaDB
     * cannot, because its VECTOR INDEX requires the indexed column to be
     * NOT NULL and rag_chunks.embedding is deliberately nullable (chunks
     * exist before embed() populates them) — this surfaces that trade-off
     * before a slow query in production does.
     */
    private function checkVectorIndex(): void
    {
        match (DB::connection($this->connection)->getDriverName()) {
            'pgsql' => $this->checkPostgresVectorIndex(),
            'mysql', 'mariadb' => $this->warn(
                '[WARN] No vector index on rag_chunks.embedding: MariaDB requires the indexed column to be NOT NULL, '.
                'but this column is deliberately nullable (see ADR-0008). Every vector search runs a full table scan '.
                'of rag_chunks on this connection — see docs/backend-support.md#vector-indexing for the recommended strategy at scale.'
            ),
            default => null,
        };
    }

    private function checkPostgresVectorIndex(): void
    {
        $index = DB::connection($this->connection)->selectOne(
            'select indexdef from pg_indexes where tablename = ? and indexname = ?',
            ['rag_chunks', 'rag_chunks_embedding_vector_index'],
        );

        if ($index === null) {
            $this->warn('[WARN] Expected vector index rag_chunks_embedding_vector_index on rag_chunks.embedding is missing — vector search will run a full table scan. See docs/backend-support.md#vector-indexing.');

            return;
        }

        $this->info("[PASS] rag_chunks.embedding has a vector index: {$index->indexdef}");
    }

    private function checkQueueDriver(): void
    {
        if (config('queue.default') === 'sync') {
            $this->warn("[WARN] queue.default is 'sync' — dependency fan-out (ADR-0005) will run inline instead of batched/queued. Fine for local development, not recommended in production.");

            return;
        }

        $this->info('[PASS] Queue is not the sync driver.');
    }

    private function checkOrphanedDependencies(): void
    {
        $count = DB::connection($this->connection)->table('rag_dependencies')
            ->whereNotIn('document_id', DB::connection($this->connection)->table('rag_documents')->select('id'))
            ->count();

        if ($count > 0) {
            $this->warn("[WARN] {$count} rag_dependencies row(s) reference a document_id that no longer exists in rag_documents. This should be impossible given the FK cascade — check whether foreign key enforcement is disabled on this connection.");

            return;
        }

        $this->info('[PASS] No orphaned rag_dependencies rows.');
    }

    private function checkFailedDocuments(): void
    {
        $failed = DB::connection($this->connection)->table('rag_documents')->where('status', 'failed');
        $count = $failed->count();

        if ($count === 0) {
            $this->info('[PASS] No documents in a failed state.');

            return;
        }

        $this->warn("[WARN] {$count} document(s) have status = 'failed'. Examples:");

        foreach ($failed->limit(3)->get(['model_type', 'model_id', 'last_error']) as $row) {
            $this->line("    - {$row->model_type} #{$row->model_id}: {$row->last_error}");
        }
    }

    /**
     * Deliberately ignores --connection: failed_jobs lives on the queue
     * connection, not the RAG data connection this command otherwise
     * checks, and the two are unrelated.
     */
    private function checkFailedJobs(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }

        $count = DB::table('failed_jobs')->count();

        if ($count > 0) {
            $this->warn("[WARN] {$count} row(s) in failed_jobs (not necessarily Eloquent RAG's — a general queue health signal).");

            return;
        }

        $this->info('[PASS] No rows in failed_jobs.');
    }
}
