<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only fixture domain: Product belongsTo Category, belongsTo Brand,
 * belongsToMany Feature. Same shape as the Phase 0 spike's fixture domain,
 * rebuilt here as package test fixtures rather than reused from the
 * throwaway scratch app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku');
            $table->decimal('price', 10, 2);
            $table->foreignId('category_id')->constrained();
            $table->foreignId('brand_id')->constrained();
            $table->timestamps();
        });

        Schema::create('feature_product', function (Blueprint $table) {
            $table->foreignId('feature_id')->constrained();
            $table->foreignId('product_id')->constrained();

            $table->primary(['feature_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('features');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
