<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Console\Commands;

use Ahmednour\EloquentRag\Console\Commands\Concerns\ProcessesRagModels;
use Ahmednour\EloquentRag\Rag;
use Illuminate\Console\Command;

class RagSyncCommand extends Command
{
    use ProcessesRagModels;

    protected $signature = 'rag:sync
        {model? : Fully-qualified model class to sync}
        {--id= : Sync only this specific model id (requires model)}
        {--dependency= : Invalidate a dependency instead, format Type:id (e.g. "App\\Models\\Category:5") — mutually exclusive with model/--id}
        {--chunk= : Override eloquent-rag.queue.batch_size for this invocation only}
        {--connection= : Connection to discover rag_documents model types from when no model argument is given (default: the app\'s default connection). Ignored when a model argument is given — its own connection is used instead.}';

    protected $description = 'Sync (structurally, then embed) one model, every instance of a model type, or invalidate a dependency directly';

    public function handle(): int
    {
        $model = $this->argument('model');
        $id = $this->option('id');
        $dependency = $this->option('dependency');
        $chunk = $this->option('chunk');

        if ($dependency !== null && ($model !== null || $id !== null)) {
            $this->error('--dependency cannot be combined with model or --id — invalidating a dependency and syncing a specific model are separate operations.');

            return self::FAILURE;
        }

        if ($id !== null && $model === null) {
            $this->error('--id requires a model argument.');

            return self::FAILURE;
        }

        if ($chunk !== null) {
            config(['eloquent-rag.queue.batch_size' => (int) $chunk]);
        }

        if ($dependency !== null) {
            return $this->invalidateDependency($dependency);
        }

        $result = $this->processModels($model, $id, force: false, connection: $this->option('connection'));

        $this->newLine();
        $this->info("Synced: {$result['synced']}, Failed: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function invalidateDependency(string $dependency): int
    {
        $parts = explode(':', $dependency, 2);

        if (count($parts) !== 2 || $parts[1] === '') {
            $this->error('--dependency must be in the form Type:id, e.g. "App\\Models\\Category:5".');

            return self::FAILURE;
        }

        [$type, $dependencyId] = $parts;

        Rag::invalidate($type, $dependencyId);

        $this->info("Invalidation dispatched for {$type} #{$dependencyId}.");

        return self::SUCCESS;
    }
}
