<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Tests;

use Ahmednour\EloquentRag\EloquentRagServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EloquentRagServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Most tests want queued jobs (created/updated/restored ->
        // rag()->queue(), and fan-out dispatch) to run inline. The
        // transaction-boundary test overrides this to a real database
        // queue driver on a separate connection — see its own setup.
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/Migrations');
    }
}
