<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0006: jobs dispatch only after the enclosing transaction commits.
 * This can give a false pass if the queue shares the app's own DB
 * connection (its own INSERT gets swept into the same ambient transaction
 * and rolls back regardless of afterCommit) — the Phase 0 spike hit this
 * exact false pass. So this suite deliberately uses a real `database`
 * queue driver on a second, physically separate SQLite connection, per
 * ADR-0006's Context section.
 */
beforeEach(function () {
    Config::set('database.connections.queue_testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    Config::set('queue.default', 'database');
    Config::set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'connection' => 'queue_testing',
    ]);

    Schema::connection('queue_testing')->create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

function jobCount(): int
{
    return DB::connection('queue_testing')->table('jobs')->count();
}

function dispatchOne(bool $afterCommit): void
{
    $pending = SyncRagDocument::dispatch([['model_type' => 'Irrelevant\\Model', 'model_id' => 1]]);

    if ($afterCommit) {
        $pending->afterCommit();
    }
}

it('drops a job dispatched with afterCommit() when the enclosing transaction rolls back', function () {
    try {
        DB::transaction(function () {
            dispatchOne(afterCommit: true);

            throw new RuntimeException('forced rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(jobCount())->toBe(0);
});

it('keeps a job dispatched with afterCommit() when the enclosing transaction commits', function () {
    DB::transaction(function () {
        dispatchOne(afterCommit: true);
    });

    expect(jobCount())->toBe(1);
});

it('leaks a job dispatched WITHOUT afterCommit() even when the transaction rolls back, proving the test is not a false pass', function () {
    try {
        DB::transaction(function () {
            dispatchOne(afterCommit: false);

            throw new RuntimeException('forced rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    // The queue's own INSERT lands on a physically separate connection
    // from the app's transaction, so it is never swept into the rollback
    // — this is exactly why afterCommit() is necessary, not incidental.
    expect(jobCount())->toBe(1);
});
