<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Support;

/**
 * The default Tokenizer: approximates token count by whitespace-delimited
 * words. This is deliberately simple and is Chunker's pre-ADR-0009
 * behavior preserved byte-for-byte — real tokenizer alignment with a
 * specific embedding model is opt-in via a custom Tokenizer driver (see
 * docs/tokenization.md), not something every install pays for.
 */
final class WhitespaceTokenizer implements Tokenizer
{
    public function encode(string $text): array
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return [];
        }

        return preg_split('/\s+/u', $trimmed) ?: [];
    }

    public function decode(array $tokens): string
    {
        return implode(' ', $tokens);
    }

    public function identifier(): string
    {
        return 'whitespace';
    }
}
