<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pgvector only — see ADR-0008. pgvector's HNSW index tolerates a nullable
 * column (NULL and zero vectors are simply excluded from the graph, which
 * is exactly RagSearch's existing whereNotNull('rag_chunks.embedding')
 * scoping). MariaDB's VECTOR INDEX has no equivalent: it requires the
 * indexed column to be declared NOT NULL, which rag_chunks.embedding
 * deliberately is not (chunks are created by sync() before embed()
 * populates them) — forcing that trade-off to satisfy one backend would
 * break the sync/embed decoupling for every consumer of this package, so
 * this migration intentionally does not add a vector index on MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('rag_chunks', function (Blueprint $table) {
            $table->vectorIndex('embedding', 'rag_chunks_embedding_vector_index');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('rag_chunks', function (Blueprint $table) {
            $table->dropVectorIndex('rag_chunks_embedding_vector_index');
        });
    }
};
