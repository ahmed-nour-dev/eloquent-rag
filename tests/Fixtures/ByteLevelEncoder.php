<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Tests\Fixtures;

use Yethee\Tiktoken\Encoder;

/**
 * A yethee/tiktoken Encoder that maps every byte to its own token — the
 * extreme case of BPE splitting a multi-byte UTF-8 character across
 * tokens, with no vocabulary download needed.
 */
final class ByteLevelEncoder implements Encoder
{
    public function getEncoding(): string
    {
        return 'bytes';
    }

    public function encode(string $text): array
    {
        return array_values(unpack('C*', $text) ?: []);
    }

    public function encodeInChunks(string $text, int $maxTokensPerChunk): array
    {
        return array_chunk($this->encode($text), $maxTokensPerChunk);
    }

    public function decode(array $tokens): string
    {
        return implode('', array_map('chr', $tokens));
    }
}
