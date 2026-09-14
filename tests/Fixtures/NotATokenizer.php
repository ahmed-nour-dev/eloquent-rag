<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Tests\Fixtures;

/**
 * A real class that does not implement Tokenizer, used to prove
 * TokenizerFactory rejects a configured driver class that doesn't
 * satisfy the contract.
 */
final class NotATokenizer {}
