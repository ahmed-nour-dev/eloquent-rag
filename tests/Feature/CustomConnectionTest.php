<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Brand;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Widget;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #30: RagDocument/RagChunk/RagDependency and the raw query-builder
 * calls around them previously always used the app's default connection,
 * silently ignoring an indexed model's own $connection. This suite proves
 * RAG data now follows the model instead — see
 * docs/installation.md#custom-database-connections.
 *
 * A second, physically separate SQLite connection is registered per test
 * (same technique as TransactionBoundaryTest), with the package's own
 * rag_* schema replicated onto it, so cross-connection leakage is a real,
 * observable assertion rather than a mock.
 */
beforeEach(function () {
    Config::set('database.connections.secondary', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    Schema::connection('secondary')->create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    migrateRagTablesOnto('secondary');
});

function migrateRagTablesOnto(string $connection): void
{
    Schema::connection($connection)->create('rag_documents', function (Blueprint $table) {
        $table->id();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->unsignedInteger('version')->default(1);
        $table->string('content_hash', 64);
        $table->string('configuration_hash', 64);
        $table->string('status')->default('pending');
        $table->string('last_error')->nullable();
        $table->timestamp('synced_at')->nullable();
        $table->timestamps();

        $table->unique(['model_type', 'model_id']);
    });

    Schema::connection($connection)->create('rag_chunks', function (Blueprint $table) {
        $table->id();
        $table->foreignId('document_id')->constrained('rag_documents')->cascadeOnDelete();
        $table->unsignedInteger('chunk_index');
        $table->string('content_hash', 64);
        $table->text('embedding')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();

        $table->unique(['document_id', 'chunk_index']);
    });

    Schema::connection($connection)->create('rag_dependencies', function (Blueprint $table) {
        $table->id();
        $table->foreignId('document_id')->constrained('rag_documents')->cascadeOnDelete();
        $table->string('dependency_type');
        $table->unsignedBigInteger('dependency_id');
        $table->timestamps();

        $table->index(['dependency_type', 'dependency_id']);
        $table->index('document_id');
    });
}

it("writes a custom-connection model's rag_documents/rag_chunks to its own connection, not the default one", function () {
    $widget = Widget::create(['name' => 'Gadget']);

    $onSecondary = RagDocument::on('secondary')
        ->where('model_type', Widget::class)
        ->where('model_id', $widget->id)
        ->first();

    expect($onSecondary)->not->toBeNull();
    expect($onSecondary->chunks()->count())->toBeGreaterThan(0);

    // Nothing leaked onto the default connection's rag_documents table.
    expect(DB::table('rag_documents')->where('model_type', Widget::class)->count())->toBe(0);
});

it('deletes the document from the correct connection via the queued forget job after the source model is gone', function () {
    $widget = Widget::create(['name' => 'Gadget']);
    $id = $widget->id;

    expect(RagDocument::on('secondary')->where('model_type', Widget::class)->where('model_id', $id)->exists())->toBeTrue();

    $widget->delete();

    expect(RagDocument::on('secondary')->where('model_type', Widget::class)->where('model_id', $id)->exists())->toBeFalse();
});

it("routes a default-connection model's RAG data onto the eloquent-rag.connection config override instead", function () {
    config(['eloquent-rag.connection' => 'secondary']);

    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = Product::create([
        'name' => 'Speaker',
        'sku' => 'SPK-001',
        'price' => 49.99,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
    ]);

    expect(
        RagDocument::on('secondary')->where('model_type', Product::class)->where('model_id', $product->id)->exists()
    )->toBeTrue();

    expect(DB::table('rag_documents')->where('model_type', Product::class)->count())->toBe(0);
});

it('still fans out dependency invalidation correctly when the config override routes everything onto one connection', function () {
    config(['eloquent-rag.connection' => 'secondary']);

    $category = Category::create(['name' => 'Electronics']);
    $brand = Brand::create(['name' => 'Acme']);
    $product = Product::create([
        'name' => 'Speaker',
        'sku' => 'SPK-001',
        'price' => 49.99,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
    ]);

    $beforeHash = RagDocument::on('secondary')
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->value('content_hash');

    $category->update(['name' => 'Consumer Electronics']);

    $afterHash = RagDocument::on('secondary')
        ->where('model_type', Product::class)
        ->where('model_id', $product->id)
        ->value('content_hash');

    expect($afterHash)->not->toBe($beforeHash);
});
