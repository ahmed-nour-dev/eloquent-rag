<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Support\Chunker;
use Ahmednour\EloquentRag\Support\TiktokenTokenizer;
use Ahmednour\EloquentRag\Support\TokenizerFactory;
use Ahmednour\EloquentRag\Tests\Fixtures\ByteLevelEncoder;
use Yethee\Tiktoken\Exception\IOError;

/**
 * Issue #71: the optional yethee/tiktoken-backed Tokenizer. The byte-level
 * encoder fixture splits every multi-byte character across tokens — the
 * worst case for chunk boundaries in Arabic and other non-Latin text —
 * without needing a vocabulary download.
 */
const ARABIC_SAMPLE = 'مرحبا بالعالم، هذا نص عربي للتجربة';

it('round-trips text through encode() and decode()', function () {
    $tokenizer = new TiktokenTokenizer(new ByteLevelEncoder);

    expect($tokenizer->decode($tokenizer->encode(ARABIC_SAMPLE)))->toBe(ARABIC_SAMPLE);
});

it('reports the encoding in its identifier so switching encodings invalidates documents', function () {
    expect((new TiktokenTokenizer(new ByteLevelEncoder))->identifier())->toBe('tiktoken:bytes');
});

it('never produces invalid UTF-8 when a chunk boundary splits a character', function (int $maxTokens, int $overlap) {
    $chunks = (new Chunker($maxTokens, $overlap, new TiktokenTokenizer(new ByteLevelEncoder)))->chunk(ARABIC_SAMPLE);

    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect(mb_check_encoding($chunk, 'UTF-8'))->toBeTrue();
        // Only whole characters from the source survive — nothing substituted.
        expect(str_contains(ARABIC_SAMPLE, $chunk))->toBeTrue();
    }
})->with([
    'odd split, overlap' => [7, 2],
    'odd split, no overlap' => [5, 0],
    'tiny chunks' => [3, 1],
]);

it('is deterministic, as ADR-0004 hashing requires', function () {
    $chunker = new Chunker(9, 3, new TiktokenTokenizer(new ByteLevelEncoder));

    expect($chunker->chunk(ARABIC_SAMPLE))->toBe($chunker->chunk(ARABIC_SAMPLE));
});

it('trims partial characters from both edges only', function () {
    $alef = 'ا'; // 2 bytes
    $euro = '€'; // 3 bytes

    expect(TiktokenTokenizer::trimPartialCharacters(substr($alef, 1).'abc'.substr($euro, 0, 2)))->toBe('abc');
    expect(TiktokenTokenizer::trimPartialCharacters('abc'.$euro))->toBe('abc'.$euro);
});

it('is selected by the tiktoken driver with a real encoding', function () {
    config(['eloquent-rag.chunk.tokenizer' => 'tiktoken']);

    try {
        $tokenizer = TokenizerFactory::make();
    } catch (IOError|ErrorException $e) {
        // No network access to the vocabulary host (Laravel turns the
        // failed fopen() warning into an ErrorException first). CI has it.

        $this->markTestSkipped('Could not download the cl100k_base vocabulary: '.$e->getMessage());

        return;
    }

    expect($tokenizer)->toBeInstanceOf(TiktokenTokenizer::class);
    expect($tokenizer->identifier())->toBe('tiktoken:cl100k_base');

    // A BPE tokenizer spends more than one token per Arabic word.
    expect(count($tokenizer->encode(ARABIC_SAMPLE)))->toBeGreaterThan(count(explode(' ', ARABIC_SAMPLE)));
    expect($tokenizer->decode($tokenizer->encode(ARABIC_SAMPLE)))->toBe(ARABIC_SAMPLE);
});
