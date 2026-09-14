<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Console\Commands;

use Ahmednour\EloquentRag\Models\RagDocument;
use Ahmednour\EloquentRag\Support\RagConnectionResolver;
use Illuminate\Console\Command;

/**
 * Deletes a document (chunks/dependencies cascade via FK) by
 * (model_type, model_id) directly against rag_documents — deliberately
 * does NOT require the underlying model to still exist or be hydratable,
 * so this works as a cleanup tool even after the model itself is gone
 * (e.g. following a raw delete rag:prune would also have caught, or when
 * you already know the id and don't want to wait for the next prune).
 */
class RagForgetCommand extends Command
{
    protected $signature = 'rag:forget {model : Fully-qualified model class} {--id= : The model id}';

    protected $description = 'Delete the document (and cascading chunks/dependencies) for a specific model, without requiring the model to still exist';

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

        $document->delete();

        $this->info("Deleted document for {$model} #{$id}.");

        return self::SUCCESS;
    }
}
