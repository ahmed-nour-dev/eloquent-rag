<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Tests\Integration;

use Ahmednour\EloquentRag\EloquentRagServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base for real-MariaDB acceptance tests (ADR-0003's actual 11.7+ floor).
 * Deliberately a SEPARATE base class from Ahmednour\EloquentRag\Tests\TestCase
 * — that class's 65 tests rely on SQLite being the *default* connection
 * (several, e.g. VectorBackendCapabilityTest, specifically test *rejection*
 * of it as an unsupported backend), so this class must never replace or be
 * merged with it.
 *
 * When RAG_TEST_MARIADB_HOST is unset (true in this sandbox, and in any
 * environment without a real MariaDB 11.7+ server), defineEnvironment()
 * falls back to the exact same safe in-memory SQLite config the rest of the
 * suite uses — purely so setUp()/RefreshDatabase has something harmless to
 * migrate against. Every test built on this base calls skip() as its first
 * line and never makes a real assertion against that fallback connection.
 * Only the CI job that provisions a real MariaDB service container sets the
 * env vars that make these tests actually run.
 */
abstract class MariaDbAcceptanceTestCase extends Orchestra
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

        $app['config']->set('database.default', 'mariadb_acceptance');
        $app['config']->set('database.connections.mariadb_acceptance', [
            'driver' => 'mariadb',
            'host' => env('RAG_TEST_MARIADB_HOST'),
            'port' => (int) env('RAG_TEST_MARIADB_PORT', 3306),
            'database' => env('RAG_TEST_MARIADB_DATABASE', 'testing'),
            'username' => env('RAG_TEST_MARIADB_USERNAME', 'root'),
            'password' => env('RAG_TEST_MARIADB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        // Small, cheap real vectors — this suite proves the round-trip and
        // query mechanics on a genuine backend, not embedding quality.
        $app['config']->set('eloquent-rag.embedding.dimensions', 8);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Fixtures/Migrations');
    }

    public static function isConfigured(): bool
    {
        return filled(env('RAG_TEST_MARIADB_HOST'));
    }
}
