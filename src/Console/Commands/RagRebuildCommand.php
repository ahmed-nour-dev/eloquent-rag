<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Console\Commands;

use Ahmednour\EloquentRag\Console\Commands\Concerns\ProcessesRagModels;
use Illuminate\Console\Command;

/**
 * Versioned, safe replacement — not delete-and-pray. Bypasses the
 * staleness short-circuit (RagSynchronizer::sync(force: true)) and bumps
 * `version` on every rebuilt document, with the document/chunk/dependency
 * writes wrapped in a single transaction (see RagSynchronizer::sync()) so
 * a failure partway through never leaves a document pointing at
 * partially-reconciled chunks or dependencies.
 */
class RagRebuildCommand extends Command
{
    use ProcessesRagModels;

    protected $signature = 'rag:rebuild
        {model? : Fully-qualified model class to rebuild}
        {--id= : Rebuild only this specific model id (requires model)}
        {--connection= : Connection to discover rag_documents model types from when no model argument is given (default: the app\'s default connection). Ignored when a model argument is given — its own connection is used instead.}';

    protected $description = 'Force re-sync and re-embed one model, or every instance of a model type, bypassing the staleness short-circuit';

    public function handle(): int
    {
        $model = $this->argument('model');
        $id = $this->option('id');

        if ($id !== null && $model === null) {
            $this->error('--id requires a model argument.');

            return self::FAILURE;
        }

        $result = $this->processModels($model, $id, force: true, connection: $this->option('connection'));

        $this->newLine();
        $this->info("Rebuilt: {$result['synced']}, Failed: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
