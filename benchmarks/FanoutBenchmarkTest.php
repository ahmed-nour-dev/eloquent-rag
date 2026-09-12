<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\DependencyInvalidator;
use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Ahmednour\EloquentRag\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

// Bound directly in this file (not via a sibling Pest.php) because this
// file is meant to be invoked by direct path
// (`vendor/bin/pest benchmarks/FanoutBenchmarkTest.php`), outside
// phpunit.xml's configured testsuites — Pest's directory-scoped uses()->in()
// binding does not reliably apply when a file is run that way. Reuses the
// main suite's TestCase purely for its proven SQLite + package-migrations
// bootstrap.
uses(TestCase::class);

/**
 * Extends the Phase 0 spike's validated methodology
 * (docs/spikes/0001-dependency-graph.md) to the build plan's actual
 * required scale (10k/100k/1M) against this package's real, permanent
 * schema — not the spike's throwaway scratch app. Same honest caveat as
 * the spike: these are SQLite numbers on this sandbox's hardware, not a
 * substitute for real MariaDB/pgvector benchmarks, but strong evidence the
 * fan-out design itself doesn't have a scaling problem.
 *
 * This file measures; docs/benchmarks.md is where the results actually get
 * published (by hand, from this run's real output — not automated, so a
 * stale number in the docs can't silently drift from what was measured
 * without someone visibly re-running this and editing the docs).
 */
function seedFanout(int $count): void
{
    $now = now();
    $batch = [];

    for ($i = 1; $i <= $count; $i++) {
        $batch[] = [
            'model_type' => Product::class,
            'model_id' => $i,
            'version' => 1,
            'content_hash' => hash('sha256', 'content-'.$i),
            'configuration_hash' => hash('sha256', 'config-'.$i),
            'status' => 'pending',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($batch) === 2000) {
            DB::table('rag_documents')->insert($batch);
            $batch = [];
        }
    }

    if ($batch !== []) {
        DB::table('rag_documents')->insert($batch);
    }

    // Every seeded document depends on the SAME single Category — this is
    // the "one rename fans out to everything" scenario the whole design
    // exists to bound, matching the spike's scenario exactly.
    $batch = [];

    foreach (DB::table('rag_documents')->pluck('id') as $documentId) {
        $batch[] = [
            'document_id' => $documentId,
            'dependency_type' => 'App\\Models\\Category',
            'dependency_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($batch) === 2000) {
            DB::table('rag_dependencies')->insert($batch);
            $batch = [];
        }
    }

    if ($batch !== []) {
        DB::table('rag_dependencies')->insert($batch);
    }
}

dataset('scales', [10_000, 100_000, 1_000_000]);

it('benchmarks reverse-lookup and fan-out dispatch at scale', function (int $count) {
    DB::table('rag_dependencies')->delete();
    DB::table('rag_documents')->delete();

    $seedStart = microtime(true);
    seedFanout($count);
    $seedTime = microtime(true) - $seedStart;

    // Same query shape as DependencyInvalidator::resolveAffectedPairs() —
    // IDs only, never hydrating Product models, per ADR-0005.
    $lookupStart = microtime(true);
    $affected = DB::table('rag_dependencies')
        ->join('rag_documents', 'rag_documents.id', '=', 'rag_dependencies.document_id')
        ->where('rag_dependencies.dependency_type', 'App\\Models\\Category')
        ->where('rag_dependencies.dependency_id', 1)
        ->distinct()
        ->get(['rag_documents.model_type', 'rag_documents.model_id']);
    $lookupTime = microtime(true) - $lookupStart;

    expect($affected)->toHaveCount($count);

    Bus::fake();

    $dispatchStart = microtime(true);
    app(DependencyInvalidator::class)->invalidate('App\\Models\\Category', 1);
    $dispatchTime = microtime(true) - $dispatchStart;

    $expectedBatches = (int) ceil($count / (int) config('eloquent-rag.queue.batch_size', 500));
    Bus::assertDispatchedTimes(SyncRagDocument::class, $expectedBatches);

    fwrite(STDOUT, sprintf(
        "\n[BENCHMARK] %s docs | seed %.2fs | lookup %.1fms | %d batches dispatched | resolve+dispatch %.2fs\n",
        number_format($count),
        $seedTime,
        $lookupTime * 1000,
        $expectedBatches,
        $dispatchTime,
    ));
})->with('scales');
