<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Support;

use InvalidArgumentException;

/**
 * Recursive, deterministic chunker: identical text + config always produces
 * identical chunk boundaries. Pure function — no I/O.
 */
final class Chunker
{
    public function __construct(
        private readonly int $maxTokens = 400,
        private readonly int $overlap = 40,
    ) {
        if ($this->maxTokens < 1) {
            throw new InvalidArgumentException('maxTokens must be at least 1.');
        }

        if ($this->overlap < 0) {
            throw new InvalidArgumentException('overlap must not be negative.');
        }

        if ($this->overlap >= $this->maxTokens) {
            throw new InvalidArgumentException('overlap must be less than maxTokens.');
        }
    }

    /**
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $tokens = $this->tokenize($text);

        if ($tokens === []) {
            return [];
        }

        $chunks = [];
        $step = $this->maxTokens - $this->overlap;
        $total = count($tokens);

        for ($start = 0; $start < $total; $start += $step) {
            $chunks[] = implode(' ', array_slice($tokens, $start, $this->maxTokens));

            if ($start + $this->maxTokens >= $total) {
                break;
            }
        }

        return $chunks;
    }

    /**
     * Approximates token count by whitespace-delimited words. This is
     * deliberately simple: real tokenizer alignment with the embedding
     * model is a Phase 3 concern (per the build plan's sequencing
     * rationale), not something Phase 1's determinism guarantee needs.
     *
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return [];
        }

        return preg_split('/\s+/u', $trimmed) ?: [];
    }
}
