<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Support\WhitespaceTokenizer;

it('encodes on whitespace boundaries', function () {
    $tokenizer = new WhitespaceTokenizer;

    expect($tokenizer->encode('one two   three'))->toBe(['one', 'two', 'three']);
});

it('returns no tokens for empty or whitespace-only text', function () {
    $tokenizer = new WhitespaceTokenizer;

    expect($tokenizer->encode(''))->toBe([]);
    expect($tokenizer->encode("   \n\t  "))->toBe([]);
});

it('decodes by joining tokens with a single space', function () {
    $tokenizer = new WhitespaceTokenizer;

    expect($tokenizer->decode(['one', 'two', 'three']))->toBe('one two three');
    expect($tokenizer->decode([]))->toBe('');
});

it('round-trips encode/decode for already-normalized text', function () {
    $tokenizer = new WhitespaceTokenizer;
    $text = 'one two three';

    expect($tokenizer->decode($tokenizer->encode($text)))->toBe($text);
});

it('reports a stable identifier', function () {
    expect((new WhitespaceTokenizer)->identifier())->toBe('whitespace');
});
