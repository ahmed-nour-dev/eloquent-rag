<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Support;

use Ahmednour\EloquentRag\Exceptions\InvalidTokenizerDriver;

/**
 * Resolves the Tokenizer named by config('eloquent-rag.chunk.tokenizer').
 * The built-in 'whitespace' driver needs no container resolution; anything
 * else is resolved through app() (not `new`) so a custom Tokenizer can
 * have its own constructor-injected dependencies, matching the rest of
 * this package's reliance on Laravel's implicit container resolution
 * rather than explicit service bindings (see ADR-0009).
 */
final class TokenizerFactory
{
    public static function make(): Tokenizer
    {
        $driver = config('eloquent-rag.chunk.tokenizer', 'whitespace');

        if ($driver === 'whitespace') {
            return new WhitespaceTokenizer;
        }

        if (! is_string($driver) || ! class_exists($driver)) {
            throw InvalidTokenizerDriver::classDoesNotExist((string) $driver);
        }

        $tokenizer = app($driver);

        if (! $tokenizer instanceof Tokenizer) {
            throw InvalidTokenizerDriver::doesNotImplementContract($driver);
        }

        return $tokenizer;
    }
}
