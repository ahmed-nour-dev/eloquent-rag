<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Support;

/**
 * A pluggable text <-> token boundary for Chunker. `encode()`/`decode()`
 * must be pure and deterministic — Chunker's ADR-0004 hashing guarantees
 * depend on it, exactly as they already depended on the built-in
 * whitespace splitting before this abstraction existed.
 */
interface Tokenizer
{
    /**
     * @return list<int|string>
     */
    public function encode(string $text): array;

    /**
     * @param  list<int|string>  $tokens
     */
    public function decode(array $tokens): string;

    /**
     * A stable identity fed into ADR-0004's configuration_hash, so
     * swapping tokenizers (or the encoding a custom tokenizer targets)
     * invalidates every document exactly like changing maxTokens/overlap
     * or the embedding model already does.
     */
    public function identifier(): string;
}
