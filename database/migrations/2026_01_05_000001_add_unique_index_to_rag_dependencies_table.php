<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RagSynchronizer::reconcileDependencies() now diffs the desired dependency
 * set against what's stored instead of unconditionally deleting and
 * reinserting every row on each sync(). That diff no longer self-heals a
 * duplicate (document_id, dependency_type, dependency_id) row the way the
 * old delete-all-then-reinsert-deduped pass incidentally did — without a
 * constraint, a duplicate created by two overlapping sync() calls for the
 * same document would persist indefinitely instead of being cleaned up on
 * the next sync. This index makes such a duplicate impossible to insert in
 * the first place, at the cost of a raised, catchable exception on the
 * losing side of that race instead of a silent double row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_dependencies', function (Blueprint $table) {
            $table->unique(['document_id', 'dependency_type', 'dependency_id'], 'rag_dependencies_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rag_dependencies', function (Blueprint $table) {
            $table->dropUnique('rag_dependencies_unique');
        });
    }
};
