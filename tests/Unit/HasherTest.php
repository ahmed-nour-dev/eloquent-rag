<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Support\Hasher;

it('is deterministic for identical inputs', function () {
    $first = Hasher::embedding('content-hash', 'openai', 'text-embedding-3-small', 1536);
    $second = Hasher::embedding('content-hash', 'openai', 'text-embedding-3-small', 1536);

    expect($first)->toBe($second);
});

it('changes when the content hash changes', function () {
    $original = Hasher::embedding('content-hash-a', 'openai', 'text-embedding-3-small', 1536);
    $changed = Hasher::embedding('content-hash-b', 'openai', 'text-embedding-3-small', 1536);

    expect($original)->not->toBe($changed);
});

it('changes when the provider changes', function () {
    $original = Hasher::embedding('content-hash', 'openai', 'text-embedding-3-small', 1536);
    $changed = Hasher::embedding('content-hash', 'anthropic', 'text-embedding-3-small', 1536);

    expect($original)->not->toBe($changed);
});

it('changes when a null provider becomes a resolved one', function () {
    $original = Hasher::embedding('content-hash', null, 'text-embedding-3-small', 1536);
    $changed = Hasher::embedding('content-hash', 'openai', 'text-embedding-3-small', 1536);

    expect($original)->not->toBe($changed);
});

it('changes when the model changes', function () {
    $original = Hasher::embedding('content-hash', 'openai', 'text-embedding-3-small', 1536);
    $changed = Hasher::embedding('content-hash', 'openai', 'text-embedding-3-large', 1536);

    expect($original)->not->toBe($changed);
});

it('changes when the dimensions change', function () {
    $original = Hasher::embedding('content-hash', 'openai', 'text-embedding-3-small', 1536);
    $changed = Hasher::embedding('content-hash', 'openai', 'text-embedding-3-small', 3072);

    expect($original)->not->toBe($changed);
});

it('returns a sha256 hex digest', function () {
    $hash = Hasher::embedding('content-hash', 'openai', 'text-embedding-3-small', 1536);

    expect($hash)->toMatch('/^[a-f0-9]{64}$/');
});
