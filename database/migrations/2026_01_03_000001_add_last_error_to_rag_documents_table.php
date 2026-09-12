<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: rag:sync/rag:rebuild catch a per-model sync/embed failure and
 * record it here instead of aborting the whole batch — rag:doctor then
 * surfaces it as a WARN with the actual message, rather than only a bare
 * 'failed' status with no diagnostic trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            $table->dropColumn('last_error');
        });
    }
};
