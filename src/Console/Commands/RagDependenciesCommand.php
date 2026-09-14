<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Console\Commands;

use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Support\RagConnectionResolver;
use Illuminate\Console\Command;

/**
 * Read-only: lists a document's declared dependency rows (per ADR-0002),
 * i.e. what would fan out to this document if changed.
 */
class RagDependenciesCommand extends Command
{
    protected $signature = 'rag:dependencies {model : Fully-qualified model class} {--id= : The model id}';

    protected $description = "List a model's document dependency rows";

    public function handle(): int
    {
        $model = $this->argument('model');
        $id = $this->option('id');

        if ($id === null) {
            $this->error('--id is required.');

            return self::FAILURE;
        }

        $document = RagDocument::on(RagConnectionResolver::resolve($model))
            ->where('model_type', $model)
            ->where('model_id', $id)
            ->first();

        if ($document === null) {
            $this->warn("No document found for {$model} #{$id}.");

            return self::FAILURE;
        }

        $dependencies = $document->dependencies()->get(['dependency_type', 'dependency_id']);

        if ($dependencies->isEmpty()) {
            $this->info("{$model} #{$id} has no declared dependencies.");

            return self::SUCCESS;
        }

        $this->info("{$model} #{$id} depends on:");

        foreach ($dependencies as $row) {
            $this->line("  - {$row->dependency_type} #{$row->dependency_id}");
        }

        return self::SUCCESS;
    }
}
