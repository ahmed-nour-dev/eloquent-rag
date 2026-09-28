<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\DependencyInvalidator;
use Ahmednour\EloquentRag\EloquentRagServiceProvider;
use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Picks the backend the benchmark runs against from RAG_BENCH_BACKEND:
 * 'sqlite' (the default — in-memory, no server needed), 'mariadb', or
 * 'pgsql'. The two real backends reuse the exact RAG_TEST_MARIADB_* /
 * RAG_TEST_PGSQL_* variables the acceptance suites already read, so the
 * same service containers .github/workflows/tests.yml provisions (and
 * .github/workflows/benchmarks.yml, which runs this file) work unchanged.
 *
 * Declared here rather than under tests/ because this file is meant to be
 * invoked by direct path (`vendor/bin/pest benchmarks/FanoutBenchmarkTest.php`),
 * outside phpunit.xml's configured testsuites — Pest's directory-scoped
 * uses()->in() binding does not reliably apply when a file is run that way.
 */
abstract class FanoutBenchmarkTestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EloquentRagServiceProvider::class, AiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'benchmark');

        $app['config']->set('database.connections.benchmark', match (self::backend()) {
            'mariadb' => [
                'driver' => 'mariadb',
                'host' => env('RAG_TEST_MARIADB_HOST', '127.0.0.1'),
                'port' => (int) env('RAG_TEST_MARIADB_PORT', 3306),
                'database' => env('RAG_TEST_MARIADB_DATABASE', 'testing'),
                'username' => env('RAG_TEST_MARIADB_USERNAME', 'root'),
                'password' => env('RAG_TEST_MARIADB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('RAG_TEST_PGSQL_HOST', '127.0.0.1'),
                'port' => (int) env('RAG_TEST_PGSQL_PORT', 5432),
                'database' => env('RAG_TEST_PGSQL_DATABASE', 'testing'),
                'username' => env('RAG_TEST_PGSQL_USERNAME', 'postgres'),
                'password' => env('RAG_TEST_PGSQL_PASSWORD', ''),
                'charset' => 'utf8',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../tests/Fixtures/Migrations');
    }

    public static function backend(): string
    {
        return (string) (env('RAG_BENCH_BACKEND') ?: 'sqlite');
    }
}

uses(FanoutBenchmarkTestCase::class);

/**
 * Extends the dependency-graph spike's validated methodology
 * (docs/spikes/0001-dependency-graph.md) to 10k/100k/1M dependent documents
 * against this package's real, permanent schema.
 *
 * This file measures; docs/benchmarks.md is where the results actually get
 * published (by hand, from a real run's output — not automated, so a stale
 * number in the docs can't silently drift from what was measured without
 * someone visibly re-running this and editing the docs). When
 * RAG_BENCH_REPORT is set to a file path (the benchmarks workflow points it
 * at $GITHUB_STEP_SUMMARY), each run also appends a Markdown table row
 * there, so a CI run's numbers can be copied straight into the docs.
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

    foreach (DB::table('rag_documents')->select('id')->lazyById(10_000) as $document) {
        $batch[] = [
            'document_id' => $document->id,
            'dependency_type' => Category::class,
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

/**
 * RAG_BENCH_SCALES (comma-separated, e.g. "10000,100000") narrows the run —
 * useful locally, where seeding 1,000,000 rows into a real server takes a
 * while. Defaults to the three published scales.
 *
 * @return list<int>
 */
function benchmarkScales(): array
{
    $configured = env('RAG_BENCH_SCALES');

    if (! is_string($configured) || trim($configured) === '') {
        return [10_000, 100_000, 1_000_000];
    }

    return array_values(array_map('intval', array_filter(array_map('trim', explode(',', $configured)))));
}

dataset('scales', benchmarkScales());

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
        ->where('rag_dependencies.dependency_type', Category::class)
        ->where('rag_dependencies.dependency_id', 1)
        ->distinct()
        ->get(['rag_documents.model_type', 'rag_documents.model_id']);
    $lookupTime = microtime(true) - $lookupStart;

    expect($affected)->toHaveCount($count);

    Bus::fake();

    $dispatchStart = microtime(true);
    app(DependencyInvalidator::class)->invalidate(Category::class, 1);
    $dispatchTime = microtime(true) - $dispatchStart;

    $expectedBatches = (int) ceil($count / (int) config('eloquent-rag.queue.batch_size', 500));
    Bus::assertDispatchedTimes(SyncRagDocument::class, $expectedBatches);

    $connection = DB::connection();
    $serverVersion = $connection->getDriverName() === 'sqlite'
        ? 'SQLite '.$connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION)
        : $connection->getDriverName().' '.$connection->getServerVersion();

    fwrite(STDOUT, sprintf(
        "\n[BENCHMARK] %s | %s docs | seed %.2fs | lookup %.1fms | %d batches dispatched | resolve+dispatch %.2fs\n",
        $serverVersion,
        number_format($count),
        $seedTime,
        $lookupTime * 1000,
        $expectedBatches,
        $dispatchTime,
    ));

    $report = env('RAG_BENCH_REPORT');

    if (is_string($report) && $report !== '') {
        file_put_contents($report, sprintf(
            "| %s | %s | %.2fs | %.1fms | %s | %.2fs |\n",
            $serverVersion,
            number_format($count),
            $seedTime,
            $lookupTime * 1000,
            number_format($expectedBatches),
            $dispatchTime,
        ), FILE_APPEND);
    }
})->with('scales');
