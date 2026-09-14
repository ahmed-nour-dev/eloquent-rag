# Tokenization

`Chunker` splits rendered document text into pieces of at most
`config('eloquent-rag.chunk.max_tokens')` "tokens", with
`config('eloquent-rag.chunk.overlap')` tokens of overlap between
consecutive chunks. What counts as a "token" is defined by a
`Tokenizer` — a pluggable contract, per
[ADR-0009](adr/0009-tokenizer-abstraction.md).

## The default: `whitespace`

```php
'chunk' => [
    'max_tokens' => 400,
    'overlap' => 40,
    'tokenizer' => 'whitespace',
],
```

`WhitespaceTokenizer` (`Ahmednour\EloquentRag\Support\WhitespaceTokenizer`)
approximates a token as a whitespace-delimited word. It needs no
dependencies and is what this package has always done — every install that
doesn't touch `chunk.tokenizer` is unaffected by this feature existing.

It's an approximation, not a real count: whitespace-word count diverges
from what an embedding model actually counts, especially for code,
punctuation-heavy text, non-English text, or long compound words. A chunk
sized to `max_tokens` words can end up meaningfully smaller or larger than
`max_tokens` real tokens once it reaches the model.

## Model-aware sizing: bring your own `Tokenizer`

This package doesn't bundle a real BPE/tiktoken-style tokenizer — see
[ADR-0009](adr/0009-tokenizer-abstraction.md) for why (short version: that's
an AI-SDK-shaped concern, out of this sync layer's remit per
[ADR-0001](adr/0001-package-boundary.md), and it would mean this package
picking and maintaining a specific third-party tokenizer package on your
behalf). Instead, implement the contract yourself, wrapping whatever
tokenizer library you trust:

```php
namespace App\Rag;

use Ahmednour\EloquentRag\Support\Tokenizer;

final class OpenAiTokenizer implements Tokenizer
{
    public function __construct(
        private readonly \SomeTiktokenBinding $encoder,
    ) {}

    public function encode(string $text): array
    {
        return $this->encoder->encode($text); // list<int> token ids
    }

    public function decode(array $tokens): string
    {
        return $this->encoder->decode($tokens);
    }

    public function identifier(): string
    {
        // Include whatever distinguishes this tokenizer's output —
        // e.g. the encoding name — so switching encodings also
        // invalidates every document via configuration_hash.
        return 'openai:cl100k_base';
    }
}
```

Point the config at it:

```php
'chunk' => [
    'max_tokens' => 400,
    'overlap' => 40,
    'tokenizer' => \App\Rag\OpenAiTokenizer::class,
],
```

`TokenizerFactory` resolves that class name through the container
(`app(\App\Rag\OpenAiTokenizer::class)`), so its constructor can depend on
anything Laravel already knows how to resolve. An unresolvable or
non-`Tokenizer` class name throws `InvalidTokenizerDriver` immediately,
rather than silently falling back to whitespace splitting.

## `encode()`/`decode()` must be pure and deterministic

Exactly like `Chunker` and `RagDocumentBuilder` already require: identical
input must always produce identical output. `Chunker`'s output feeds
[ADR-0004](adr/0004-identity-and-hashing-scheme.md)'s `content_hash`, and a
tokenizer that isn't deterministic makes that hash meaningless — staleness
detection would silently break.

## Changing the tokenizer invalidates everything

`Tokenizer::identifier()` is fed into `configuration_hash` alongside
`max_tokens`/`overlap`/the embedding model. Changing `chunk.tokenizer` (or
changing what a custom tokenizer's `identifier()` reports) invalidates
every document's chunks/embeddings automatically, the same way changing the
embedding model does — see
[rebuild-and-migration.md](rebuild-and-migration.md) for pushing that
re-sync through immediately with `rag:rebuild` rather than waiting for the
next unrelated save.
