<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Exceptions;

use Ahmednour\EloquentRag\Support\Tokenizer;
use InvalidArgumentException;

/**
 * Thrown by TokenizerFactory::make() when `eloquent-rag.chunk.tokenizer`
 * names something other than 'whitespace' or a real Tokenizer
 * implementation — see ADR-0009 (docs/adr/0009-tokenizer-abstraction.md).
 */
final class InvalidTokenizerDriver extends InvalidArgumentException
{
    public static function classDoesNotExist(string $driver): self
    {
        return new self(sprintf(
            "Tokenizer driver '%s' is not 'whitespace' and no such class exists. ".
            'config(\'eloquent-rag.chunk.tokenizer\') must be \'whitespace\' or the fully-qualified class name of a class implementing %s '
            .'(see ADR-0009, docs/adr/0009-tokenizer-abstraction.md).',
            $driver, Tokenizer::class,
        ));
    }

    public static function doesNotImplementContract(string $driver): self
    {
        return new self(sprintf(
            "Tokenizer driver '%s' exists but does not implement %s. ".
            'config(\'eloquent-rag.chunk.tokenizer\') must be \'whitespace\' or the fully-qualified class name of a class implementing %s '
            .'(see ADR-0009, docs/adr/0009-tokenizer-abstraction.md).',
            $driver, Tokenizer::class, Tokenizer::class,
        ));
    }
}
