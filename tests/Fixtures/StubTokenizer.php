<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Tests\Fixtures;

use Ahmednour\EloquentRag\Support\Tokenizer;

/**
 * A minimal, deliberately-not-whitespace Tokenizer double used to prove
 * TokenizerFactory resolves a configured class name through the
 * container, and that configuration_hash changes when the tokenizer
 * driver changes.
 */
final class StubTokenizer implements Tokenizer
{
    public function encode(string $text): array
    {
        return [$text];
    }

    public function decode(array $tokens): string
    {
        return implode('', $tokens);
    }

    public function identifier(): string
    {
        return 'stub-fixture-tokenizer';
    }
}
