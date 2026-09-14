# ADR-0009: Tokenizer abstraction — pluggable, whitespace by default, no bundled BPE driver

**Status:** Proposed

## Context

[Issue #12](https://github.com/ahmed-nour-dev/eloquent-rag/issues/12) points
at `Chunker`'s existing token approximation:

```php
preg_split('/\s+/u', $text)
```

Whitespace-word count is a poor proxy for what an embedding model actually
counts. It diverges sharply from real BPE token count for code,
punctuation-heavy text, non-English text, and long compound words — so a
chunk sized to `maxTokens` words can end up far smaller or larger than
`maxTokens` real tokens once it reaches the embedding model. `Chunker`'s own
docblock already flagged this as deliberately deferred: "real tokenizer
alignment with the embedding model is a Phase 3 concern... not something
Phase 1's determinism guarantee needs." This ADR is that Phase 3 concern.

The obvious "just add a tiktoken dependency" fix runs straight into
[ADR-0001](0001-package-boundary.md)'s boundary: this package is a sync
layer above Laravel AI, not an AI SDK, and explicitly never owns embedding
generation. A bundled BPE implementation would mean picking one specific
third-party PHP tokenizer package (several exist, of varying maturity and
maintenance), taking on its correctness/security/upkeep as this package's
own problem, and hard-coding an encoding-to-model mapping that drifts every
time a provider ships a new model — exactly the kind of AI-SDK-shaped scope
creep ADR-0001 and [docs/principles.md](../principles.md)'s non-goals list
warn against, even though "no custom `EmbeddingProvider` abstraction" isn't
a word-for-word match for "no bundled tokenizer."

## Decision

- Introduce a `Tokenizer` contract
  (`Ahmednour\EloquentRag\Support\Tokenizer`): `encode(string): list<int|string>`,
  `decode(list<int|string>): string`, `identifier(): string`. `Chunker`
  depends on this contract instead of hard-coding word-splitting.
- Ship exactly one built-in implementation, `WhitespaceTokenizer`, which is
  `Chunker`'s pre-existing behavior extracted verbatim (byte-identical
  output). It stays the default (`config('eloquent-rag.chunk.tokenizer') ===
  'whitespace'`), so every existing install is unaffected by this change.
- `TokenizerFactory::make()` resolves the configured driver: `'whitespace'`
  needs no container resolution; any other value is treated as the
  fully-qualified class name of an app-supplied `Tokenizer` implementation
  and resolved via `app($driver)` (so it can have its own constructor-injected
  dependencies), then type-checked. A bad driver name — nonexistent class,
  or a class that doesn't implement `Tokenizer` — throws
  `InvalidTokenizerDriver` immediately, loudly, rather than silently falling
  back to whitespace splitting.
- **Deliberately not bundled**: any real BPE/tiktoken-style tokenizer. Apps
  that need exact model-aware sizing (e.g. matching OpenAI's `cl100k_base`/
  `o200k_base` encodings) bring their own binding and wrap it in a small
  `Tokenizer` implementation — see
  [docs/tokenization.md](../tokenization.md) for a worked example. This
  keeps `composer.json`'s dependency list unchanged and keeps the choice of
  (and trust in) a specific tokenizer library where it belongs: with the
  application, not this sync layer.
- `Tokenizer::identifier()` feeds into `chunkOptions()`
  (`RagSynchronizer::chunkOptions()`), which already flows into
  [ADR-0004](0004-identity-and-hashing-scheme.md)'s `configuration_hash`
  alongside `maxTokens`/`overlap`. Swapping tokenizers — or changing what a
  custom tokenizer's `identifier()` reports — therefore invalidates every
  document's stored chunks/embeddings exactly like changing `max_tokens` or
  the embedding model already does, with no separate migration step.

## Consequences

- Zero behavior change for any install that doesn't touch
  `eloquent-rag.chunk.tokenizer` — `WhitespaceTokenizer` is exactly the old
  `Chunker::tokenize()` logic.
- This package's capability ceiling for "real" token-aware chunking is
  bounded by what the application provides, per ADR-0001's existing
  trade-off (compare ADR-0003's backend-support ceiling). This is
  deliberate, not an oversight: it's what keeps this a sync layer rather
  than an AI SDK.
- A custom `Tokenizer`'s `encode()`/`decode()` must stay pure and
  deterministic for the same reason `Chunker` itself must — ADR-0004's
  hash-based staleness detection is meaningless otherwise. This is the same
  determinism requirement the package already imposed on rendering and
  chunking; it now extends to tokenization.
- If Laravel AI ever ships its own tokenizer/token-counting primitive for a
  given provider, the correct move per ADR-0001 is to have an app-level
  `Tokenizer` implementation delegate to it (or for this ADR to be revisited
  to recommend that as the default path) — not to duplicate it here.
