<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Illuminate\Support\ServiceProvider;

class EloquentRagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eloquent-rag.php', 'eloquent-rag');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/eloquent-rag.php' => config_path('eloquent-rag.php'),
            ], 'eloquent-rag-config');
        }
    }
}
