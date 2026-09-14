<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Exceptions\InvalidTokenizerDriver;
use Ahmednour\EloquentRag\Support\TokenizerFactory;
use Ahmednour\EloquentRag\Support\WhitespaceTokenizer;
use Ahmednour\EloquentRag\Tests\Fixtures\NotATokenizer;
use Ahmednour\EloquentRag\Tests\Fixtures\StubTokenizer;

it('resolves the whitespace tokenizer by default', function () {
    expect(TokenizerFactory::make())->toBeInstanceOf(WhitespaceTokenizer::class);
});

it('resolves the whitespace tokenizer when explicitly configured', function () {
    config(['eloquent-rag.chunk.tokenizer' => 'whitespace']);

    expect(TokenizerFactory::make())->toBeInstanceOf(WhitespaceTokenizer::class);
});

it('resolves a custom tokenizer class through the container', function () {
    config(['eloquent-rag.chunk.tokenizer' => StubTokenizer::class]);

    expect(TokenizerFactory::make())->toBeInstanceOf(StubTokenizer::class);
});

it('throws when the configured driver class does not exist', function () {
    config(['eloquent-rag.chunk.tokenizer' => 'App\\Rag\\DoesNotExistTokenizer']);

    expect(fn () => TokenizerFactory::make())->toThrow(InvalidTokenizerDriver::class);
});

it('throws when the configured driver class does not implement Tokenizer', function () {
    config(['eloquent-rag.chunk.tokenizer' => NotATokenizer::class]);

    expect(fn () => TokenizerFactory::make())->toThrow(InvalidTokenizerDriver::class);
});
