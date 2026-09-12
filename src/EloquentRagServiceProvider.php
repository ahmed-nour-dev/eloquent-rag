<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Ahmednour\EloquentRag\Console\Commands\RagDependenciesCommand;
use Ahmednour\EloquentRag\Console\Commands\RagDoctorCommand;
use Ahmednour\EloquentRag\Console\Commands\RagForgetCommand;
use Ahmednour\EloquentRag\Console\Commands\RagPruneCommand;
use Ahmednour\EloquentRag\Console\Commands\RagRebuildCommand;
use Ahmednour\EloquentRag\Console\Commands\RagStatusCommand;
use Ahmednour\EloquentRag\Console\Commands\RagSyncCommand;
use Ahmednour\EloquentRag\Models\RagChunk;
use Ahmednour\EloquentRag\Models\RagDependency;
use Ahmednour\EloquentRag\Models\RagDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class EloquentRagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eloquent-rag.php', 'eloquent-rag');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->registerDependencyChangeListener();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/eloquent-rag.php' => config_path('eloquent-rag.php'),
            ], 'eloquent-rag-config');

            $this->commands([
                RagDoctorCommand::class,
                RagStatusCommand::class,
                RagSyncCommand::class,
                RagRebuildCommand::class,
                RagPruneCommand::class,
                RagForgetCommand::class,
                RagDependenciesCommand::class,
            ]);
        }
    }

    /**
     * Any model's `saved` event might be a change to a declared dependency
     * (per ADR-0002, dependencies are declared by other models, not by the
     * dependency itself, so this has to listen globally rather than only
     * on HasRag-using models). The invalidator's own lookup is a cheap,
     * indexed no-op when nothing depends on the saved model.
     */
    private function registerDependencyChangeListener(): void
    {
        Event::listen('eloquent.saved: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;

            if (! $model instanceof Model) {
                return;
            }

            // Package's own bookkeeping tables — never a declared
            // dependency, and listening here would mean every sync()
            // triggers a wasted invalidation lookup on its own writes.
            if ($model instanceof RagDocument || $model instanceof RagChunk || $model instanceof RagDependency) {
                return;
            }

            app(DependencyInvalidator::class)->invalidate($model::class, $model->getKey());
        });
    }
}
