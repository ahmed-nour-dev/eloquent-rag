<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reporting — no side effects. See rag:doctor for a health
 * check; this is just "what's in the tables right now".
 */
class RagStatusCommand extends Command
{
    protected $signature = 'rag:status';

    protected $description = 'Report document/chunk/dependency counts, by status and model type';

    public function handle(): int
    {
        $totalDocuments = DB::table('rag_documents')->count();
        $totalChunks = DB::table('rag_chunks')->count();
        $totalDependencies = DB::table('rag_dependencies')->count();

        $this->info("Documents: {$totalDocuments}");
        $this->info("Chunks: {$totalChunks}");
        $this->info("Dependencies: {$totalDependencies}");

        if ($totalDocuments === 0) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('By status:');
        $this->table(
            ['status', 'count'],
            DB::table('rag_documents')
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->orderByDesc('count')
                ->get()
                ->map(fn (object $row): array => [$row->status, $row->count])
                ->all(),
        );

        $this->newLine();
        $this->line('By model type:');
        $this->table(
            ['model_type', 'count'],
            DB::table('rag_documents')
                ->select('model_type', DB::raw('count(*) as count'))
                ->groupBy('model_type')
                ->orderByDesc('count')
                ->get()
                ->map(fn (object $row): array => [$row->model_type, $row->count])
                ->all(),
        );

        return self::SUCCESS;
    }
}
