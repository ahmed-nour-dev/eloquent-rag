<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedInteger('version')->default(1);
            $table->string('content_hash', 64);
            $table->string('configuration_hash', 64);
            $table->string('status')->default('pending');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_documents');
    }
};
