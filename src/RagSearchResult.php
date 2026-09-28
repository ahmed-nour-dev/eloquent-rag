<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

use Ahmednour\EloquentRag\Support\Chunker;
use Ahmednour\EloquentRag\Support\Hasher;
use Ahmednour\EloquentRag\Support\RagDocumentBuilder;
use Ahmednour\EloquentRag\Support\TokenizerFactory;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/**
 * One match from RagSearch::searchWithScores(): the hydrated owner model,
 * how similar its best-matching chunk was to the query, and which chunk
 * that was — what an LLM-grounding or citation use case needs, rather
 * than only the bare owner model search() returns.
 *
 * `score` is cosine similarity (1.0 = identical direction, 0.0 =
 * unrelated), i.e. `1 - distance`, the same scale search()'s
 * $minSimilarity uses.
 *
 * @implements Arrayable<string, mixed>
 */
final class RagSearchResult implements Arrayable
{
    private bool $chunkResolved = false;

    private ?string $chunk = null;

    public function __construct(
        public readonly Model $model,
        public readonly float $score,
        public readonly float $distance,
        public readonly ?int $chunkIndex = null,
        private readonly ?string $chunkContentHash = null,
        ?string $chunk = null,
    ) {
        if ($chunk !== null) {
            $this->chunk = $chunk;
            $this->chunkResolved = true;
        }
    }

    /**
     * The text of the best-matching chunk.
     *
     * Chunk text is not persisted (ADR-0004 stores hashes, not content), so
     * it is re-derived on first access by rendering and chunking the owner
     * model exactly the way sync() does, then memoized. Returns null rather
     * than a guess when the re-derived chunk's hash no longer matches the
     * chunk that was actually embedded and matched — i.e. the model changed
     * after its last sync()/embed() and the stored vector describes text
     * that no longer exists. Calling this lazy-loads whatever relations
     * the model's definition renders, so only call it for results you use.
     */
    public function chunk(): ?string
    {
        if ($this->chunkResolved) {
            return $this->chunk;
        }

        $this->chunkResolved = true;

        if ($this->chunkIndex === null || ! method_exists($this->model, 'toRagDefinition')) {
            return null;
        }

        $rendered = (new RagDocumentBuilder)->render($this->model, $this->model->toRagDefinition());
        $chunks = (new Chunker(
            (int) config('eloquent-rag.chunk.max_tokens', 400),
            (int) config('eloquent-rag.chunk.overlap', 40),
            TokenizerFactory::make(),
        ))->chunk($rendered);

        $text = $chunks[$this->chunkIndex] ?? null;

        if ($text === null || ($this->chunkContentHash !== null && Hasher::content($text) !== $this->chunkContentHash)) {
            return null;
        }

        return $this->chunk = $text;
    }

    /**
     * @return array{model: Model, score: float, distance: float, chunk_index: int|null, chunk: string|null}
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'score' => $this->score,
            'distance' => $this->distance,
            'chunk_index' => $this->chunkIndex,
            'chunk' => $this->chunk(),
        ];
    }
}
