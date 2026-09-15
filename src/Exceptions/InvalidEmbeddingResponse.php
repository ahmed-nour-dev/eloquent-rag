<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Exceptions;

use RuntimeException;

/**
 * Thrown by RagSynchronizer::embed() before it writes anything to
 * rag_chunks — see issue #42. laravel/ai's own count check
 * (EmbeddingsCountMismatchException) only fires on its individual-caching
 * path, so a plain (uncached, or shared-cache) generate() call can return a
 * mismatched or malformed response with nothing catching it upstream. This
 * package validates the response itself, positionally, before the write
 * loop runs — so a bad response fails the whole batch instead of silently
 * misaligning `$response->embeddings[$index]` against `$pendingChunks`, or
 * persisting a truncated vector into a fixed-width vector column.
 */
final class InvalidEmbeddingResponse extends RuntimeException
{
    public static function countMismatch(int $expected, int $actual): self
    {
        return new self(sprintf(
            'The embedding provider returned %d embedding(s) for %d input(s). Refusing to persist a positionally-misaligned batch — see issue #42.',
            $actual,
            $expected,
        ));
    }

    public static function dimensionMismatch(int $index, int $expected, int $actual): self
    {
        return new self(sprintf(
            'The embedding provider returned an embedding of %d dimension(s) at index %d, but eloquent-rag.embedding.dimensions is configured as %d. Refusing to persist a malformed vector — see issue #42.',
            $actual,
            $index,
            $expected,
        ));
    }
}
