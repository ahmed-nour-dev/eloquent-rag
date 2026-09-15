<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_chunks', function (Blueprint $table) {
            $table->string('embedding_provider')->nullable()->after('embedding');
            $table->string('embedding_model')->nullable()->after('embedding_provider');
            $table->unsignedInteger('embedding_dimensions')->nullable()->after('embedding_model');
            $table->string('embedding_hash', 64)->nullable()->after('embedding_dimensions');
        });
    }

    public function down(): void
    {
        Schema::table('rag_chunks', function (Blueprint $table) {
            $table->dropColumn(['embedding_provider', 'embedding_model', 'embedding_dimensions', 'embedding_hash']);
        });
    }
};
