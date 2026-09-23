<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Exceptions;

use RuntimeException;

/**
 * Thrown by RagSearch's ADR-0011 fallback ranking path when the number of
 * candidate chunks it would have to pull into PHP and compare one at a
 * time exceeds config('eloquent-rag.fallback.max_candidate_chunks') — a
 * clear, actionable failure instead of the fallback silently getting
 * slower as data grows.
 */
final class FallbackCandidateLimitExceeded extends RuntimeException
{
    public static function exceeded(int $actual, int $max): self
    {
        return new self(sprintf(
            'The PHP-side fallback vector search (ADR-0011) would need to scan %d chunk(s), above config(\'eloquent-rag.fallback.max_candidate_chunks\') = %d. This fallback is for development/small-scale use only — either raise the limit if you understand the cost, or move to a supported backend (MariaDB 11.7+ or PostgreSQL+pgvector) for production-scale search. See docs/backend-support.md#fallback-backend.',
            $actual,
            $max,
        ));
    }
}
