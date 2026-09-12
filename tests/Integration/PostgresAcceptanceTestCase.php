<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Tests\Integration;

use Ahmednour\EloquentRag\EloquentRagServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base for real-PostgreSQL+pgvector acceptance tests. See
 * MariaDbAcceptanceTestCase's docblock — same rationale, same safe-fallback
 * pattern, deliberately kept separate from the main SQLite-based TestCase.
 */
abstract class PostgresAcceptanceTestCase extends Orchestra
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

        if (! static::isConfigured()) {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

            return;
        }

        $app['config']->set('database.default', 'pgsql_acceptance');
        $app['config']->set('database.connections.pgsql_acceptance', [
            'driver' => 'pgsql',
            'host' => env('RAG_TEST_PGSQL_HOST'),
            'port' => (int) env('RAG_TEST_PGSQL_PORT', 5432),
            'database' => env('RAG_TEST_PGSQL_DATABASE', 'testing'),
            'username' => env('RAG_TEST_PGSQL_USERNAME', 'postgres'),
            'password' => env('RAG_TEST_PGSQL_PASSWORD', ''),
            'charset' => 'utf8',
        ]);

        $app['config']->set('eloquent-rag.embedding.dimensions', 8);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Fixtures/Migrations');
    }

    public static function isConfigured(): bool
    {
        return filled(env('RAG_TEST_PGSQL_HOST'));
    }
}
