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

## Model-aware sizing: the `tiktoken` driver

For real BPE token counts — what OpenAI-style embedding models actually
count — install the optional [`yethee/tiktoken`](https://github.com/yethee/tiktoken-php)
package (a PHP port of OpenAI's tiktoken) and switch drivers:

```bash
composer require yethee/tiktoken
```

```php
'chunk' => [
    'max_tokens' => 400,
    'overlap' => 40,
    'tokenizer' => 'tiktoken',
    'tiktoken_encoding' => 'cl100k_base', // text-embedding-3-small/-large, ada-002
],
```

Pick the encoding your embedding model uses (`cl100k_base` for OpenAI's
current embedding models; `o200k_base`, `p50k_base`, and `r50k_base` are
also available). This package doesn't map model names to encodings for
you — that mapping changes whenever a provider ships a model. The driver
reports `tiktoken:<encoding>` as its identifier, so changing the encoding
invalidates every document like any other tokenizer change.

Things to know:

- **This matters most for non-English text.** Whitespace counting treats
  an Arabic word as one token, but a BPE tokenizer typically spends several
  on it, so whitespace-sized chunks of Arabic (or other non-Latin script)
  text can be several times larger than `max_tokens` real tokens.
- **The vocabulary is downloaded on first use.** `yethee/tiktoken` fetches
  the encoding's vocabulary file from `openaipublic.blob.core.windows.net`
  the first time it's needed, and caches it in `sys_get_temp_dir()/tiktoken`
  (override with the `TIKTOKEN_CACHE_DIR` environment variable). On servers
  without outbound internet access, pre-populate that cache directory at
  deploy time. The package keeps one loaded encoder per encoding in memory
  per process.
- **Chunk edges never split a character.** BPE tokens are bytes, so a chunk
  boundary can fall in the middle of a multi-byte UTF-8 character. The
  driver drops such a partial character at the edge of a chunk rather than
  sending invalid UTF-8 to your embedding provider; chunk overlap means the
  character still appears whole in the neighboring chunk.
- Without `yethee/tiktoken` installed, selecting `tiktoken` throws
  `InvalidTokenizerDriver` telling you what to install.

## Bring your own `Tokenizer`

For any other tokenizer — a different library, a provider-specific one, or
a non-OpenAI embedding model — implement the contract yourself. See
[ADR-0009](adr/0009-tokenizer-abstraction.md) for why the package ships
only the two drivers above:

```php
namespace App\Rag;

use Ahmednour\EloquentRag\Support\Tokenizer;

final class MyModelTokenizer implements Tokenizer
{
    public function __construct(
        private readonly \SomeTokenizerLibrary $encoder,
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
        // e.g. the vocabulary name — so switching it also
        // invalidates every document via configuration_hash.
        return 'my-model:v1';
    }
}
```

Point the config at it:

```php
'chunk' => [
    'max_tokens' => 400,
    'overlap' => 40,
    'tokenizer' => \App\Rag\MyModelTokenizer::class,
],
```

`TokenizerFactory` resolves that class name through the container
(`app(\App\Rag\MyModelTokenizer::class)`), so its constructor can depend on
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
