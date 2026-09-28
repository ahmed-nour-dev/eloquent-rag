<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Support;

use Ahmednour\EloquentRag\Exceptions\InvalidTokenizerDriver;
use Yethee\Tiktoken\Encoder;
use Yethee\Tiktoken\EncoderProvider;

/**
 * Optional, model-aware Tokenizer backed by yethee/tiktoken, a PHP port of
 * OpenAI's tiktoken (issue #71). Selected with
 * `config('eloquent-rag.chunk.tokenizer') === 'tiktoken'`; the BPE encoding
 * comes from `chunk.tiktoken_encoding` (default cl100k_base, the encoding
 * OpenAI's text-embedding-3-* models use). yethee/tiktoken is only a
 * `suggest`ed dependency — install it yourself to use this driver.
 *
 * Why this matters most for non-Latin scripts: WhitespaceTokenizer counts
 * one Arabic word as one "token", but a BPE tokenizer typically spends
 * several tokens on it, so whitespace-sized chunks can run well past the
 * embedding model's real token budget.
 *
 * BPE tokens are byte sequences, and a multi-byte UTF-8 character (every
 * Arabic letter, for instance) can be split across two tokens. When
 * Chunker slices the token list at a chunk boundary, the decoded slice
 * can therefore start or end with a fragment of a character. decode()
 * drops those fragments rather than passing invalid UTF-8 on to the
 * embedding provider — deterministically, so ADR-0004 hashing still holds.
 * At most one character is lost at each edge of a chunk, and chunk overlap
 * means it still appears whole in the neighboring chunk.
 */
final class TiktokenTokenizer implements Tokenizer
{
    /** @var array<string, Encoder> Loaded encoders, per encoding name, for this process. */
    private static array $encoders = [];

    public function __construct(private readonly Encoder $encoder) {}

    /**
     * Builds the tokenizer TokenizerFactory uses for the 'tiktoken' driver.
     *
     * @throws InvalidTokenizerDriver when yethee/tiktoken isn't installed
     */
    public static function fromConfig(): self
    {
        if (! class_exists(EncoderProvider::class)) {
            throw InvalidTokenizerDriver::missingDependency('tiktoken', 'yethee/tiktoken');
        }

        $encoding = (string) config('eloquent-rag.chunk.tiktoken_encoding', 'cl100k_base');

        // TokenizerFactory::make() runs several times per sync; loading and
        // parsing a ~100k-entry vocabulary each time would dominate it.
        return new self(self::$encoders[$encoding] ??= (new EncoderProvider)->get($encoding));
    }

    /**
     * @return list<int>
     */
    public function encode(string $text): array
    {
        return $this->encoder->encode($text);
    }

    /**
     * @param  list<int|string>  $tokens
     */
    public function decode(array $tokens): string
    {
        return self::trimPartialCharacters($this->encoder->decode(array_map('intval', $tokens)));
    }

    public function identifier(): string
    {
        return 'tiktoken:'.$this->encoder->getEncoding();
    }

    /**
     * Drops an incomplete UTF-8 sequence from either end of $text — the
     * only places a token-slice boundary can split a character, since the
     * middle of a slice is a contiguous run of the original text.
     */
    public static function trimPartialCharacters(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // Leading continuation bytes (10xxxxxx) belong to a character that
        // started in the previous slice.
        $text = (string) preg_replace('/^[\x80-\xBF]{1,3}/', '', $text);

        // A lead byte near the end whose sequence is cut short.
        $length = strlen($text);

        for ($offset = 1; $offset <= min(3, $length); $offset++) {
            $byte = ord($text[$length - $offset]);

            if ($byte < 0x80) {
                break;
            }

            if ($byte >= 0xC0) {
                $sequenceLength = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                if ($sequenceLength > $offset) {
                    $text = substr($text, 0, $length - $offset);
                }

                break;
            }
        }

        // Anything still invalid didn't come from a slice boundary; replace
        // it deterministically rather than forwarding invalid UTF-8.
        return mb_scrub($text, 'UTF-8');
    }
}
