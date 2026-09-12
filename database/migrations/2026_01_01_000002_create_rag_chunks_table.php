<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('rag_documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('content_hash', 64);

            // Placeholder column: Phase 1 never writes to it (see the plan's
            // exit criteria — no DB writes to vector columns yet). Phase 3
            // replaces this with Laravel's native vector column type once a
            // supported backend (MariaDB 11.7+/pgvector, per ADR-0003) is
            // configured. Nullable text is used now rather than JSON so this
            // placeholder doesn't imply a JSON-array serialization that the
            // real vector column type may not match.
            $table->text('embedding')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_chunks');
    }
};
