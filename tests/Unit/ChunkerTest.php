<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Support\Chunker;
use Ahmednour\EloquentRag\Support\Tokenizer;

it('chunks deterministically for identical input and config', function () {
    $chunker = new Chunker(maxTokens: 5, overlap: 2);
    $text = 'one two three four five six seven eight nine ten';

    $first = $chunker->chunk($text);
    $second = $chunker->chunk($text);

    expect($first)->toBe($second);
});

it('respects maxTokens and overlap boundaries', function () {
    $chunker = new Chunker(maxTokens: 4, overlap: 1);
    $text = 'a b c d e f g h';

    expect($chunker->chunk($text))->toBe([
        'a b c d',
        'd e f g',
        'g h',
    ]);
});

it('produces a single chunk when the text fits within maxTokens', function () {
    $chunker = new Chunker(maxTokens: 10, overlap: 2);

    expect($chunker->chunk('one two three'))->toBe(['one two three']);
});

it('returns no chunks for empty or whitespace-only text', function () {
    $chunker = new Chunker(maxTokens: 10, overlap: 2);

    expect($chunker->chunk(''))->toBe([]);
    expect($chunker->chunk("   \n\t  "))->toBe([]);
});

it('rejects overlap greater than or equal to maxTokens', function () {
    expect(fn () => new Chunker(maxTokens: 4, overlap: 4))->toThrow(InvalidArgumentException::class);
    expect(fn () => new Chunker(maxTokens: 4, overlap: 5))->toThrow(InvalidArgumentException::class);
});

it('rejects a non-positive maxTokens', function () {
    expect(fn () => new Chunker(maxTokens: 0, overlap: 0))->toThrow(InvalidArgumentException::class);
});

it('delegates token splitting to the injected tokenizer instead of hard-coding whitespace', function () {
    $tokenizer = new class implements Tokenizer
    {
        public function encode(string $text): array
        {
            // Splits on '|' instead of whitespace, so this only passes
            // if Chunker actually uses the injected tokenizer.
            return explode('|', $text);
        }

        public function decode(array $tokens): string
        {
            return implode('-', $tokens);
        }

        public function identifier(): string
        {
            return 'pipe-delimited';
        }
    };

    $chunker = new Chunker(maxTokens: 2, overlap: 0, tokenizer: $tokenizer);

    expect($chunker->chunk('a|b|c|d'))->toBe(['a-b', 'c-d']);
});
