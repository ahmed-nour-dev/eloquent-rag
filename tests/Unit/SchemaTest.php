<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDependency;
use Ahmednour\EloquentRag\Models\RagDocument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates the rag_documents, rag_chunks, and rag_dependencies tables', function () {
    expect(Schema::hasTable('rag_documents'))->toBeTrue();
    expect(Schema::hasTable('rag_chunks'))->toBeTrue();
    expect(Schema::hasTable('rag_dependencies'))->toBeTrue();
});

it('allows a document with chunks and dependencies to be persisted and related', function () {
    $document = RagDocument::create([
        'model_type' => 'App\\Models\\Product',
        'model_id' => 1,
        'content_hash' => str_repeat('a', 64),
        'configuration_hash' => str_repeat('b', 64),
    ]);

    $chunk = $document->chunks()->create([
        'chunk_index' => 0,
        'content_hash' => str_repeat('c', 64),
        'metadata' => ['tokens' => 42],
    ]);

    $dependency = $document->dependencies()->create([
        'dependency_type' => 'App\\Models\\Category',
        'dependency_id' => 5,
    ]);

    expect($chunk)->toBeInstanceOf(RagChunk::class);
    expect($chunk->metadata)->toBe(['tokens' => 42]);
    expect($dependency)->toBeInstanceOf(RagDependency::class);
    expect($document->fresh()->chunks)->toHaveCount(1);
    expect($document->fresh()->dependencies)->toHaveCount(1);
});

it('enforces uniqueness of (model_type, model_id) on rag_documents', function () {
    RagDocument::create([
        'model_type' => 'App\\Models\\Product',
        'model_id' => 1,
        'content_hash' => str_repeat('a', 64),
        'configuration_hash' => str_repeat('b', 64),
    ]);

    expect(fn () => RagDocument::create([
        'model_type' => 'App\\Models\\Product',
        'model_id' => 1,
        'content_hash' => str_repeat('d', 64),
        'configuration_hash' => str_repeat('e', 64),
    ]))->toThrow(QueryException::class);
});

it('cascades chunk and dependency deletion when the document is deleted', function () {
    $document = RagDocument::create([
        'model_type' => 'App\\Models\\Product',
        'model_id' => 1,
        'content_hash' => str_repeat('a', 64),
        'configuration_hash' => str_repeat('b', 64),
    ]);

    $document->chunks()->create(['chunk_index' => 0, 'content_hash' => str_repeat('c', 64)]);
    $document->dependencies()->create(['dependency_type' => 'App\\Models\\Category', 'dependency_id' => 5]);

    $document->delete();

    expect(RagChunk::count())->toBe(0);
    expect(RagDependency::count())->toBe(0);
});
