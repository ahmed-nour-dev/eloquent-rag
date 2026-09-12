<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('rag_documents')->cascadeOnDelete();
            $table->string('dependency_type');
            $table->unsignedBigInteger('dependency_id');
            $table->timestamps();

            // The hot path per the Phase 0 spike: reverse lookup by
            // (dependency_type, dependency_id) resolves affected document
            // IDs without hydrating models. Confirmed at 38ms/50k docs and
            // 179ms/200k docs — docs/spikes/0001-dependency-graph.md.
            $table->index(['dependency_type', 'dependency_id']);
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_dependencies');
    }
};
