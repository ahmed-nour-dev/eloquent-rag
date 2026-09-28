# Changelog

All notable changes to this package are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
(while pre-1.0, a minor version may contain breaking changes; they are
called out below).

<!--
  Use absolute https://github.com/... URLs for links in this file: the
  release workflow publishes each version's section as GitHub release
  notes, where relative paths like docs/foo.md don't resolve.
-->

## [Unreleased]

## [0.2.0-beta.1] - 2026-09-28

Second beta. Not yet recommended for production: see
[Status](https://github.com/ahmed-nour-dev/eloquent-rag#status) for what that means.

### Added

- **Portable fallback backend** (opt-in, development/small data only):
  `embed()` and search now work on SQLite and plain MySQL when
  `eloquent-rag.portable_fallback.enabled` / `RAG_PORTABLE_FALLBACK` is on,
  storing JSON embeddings and ranking by cosine similarity in PHP. See
  [ADR-0011](https://github.com/ahmed-nour-dev/eloquent-rag/blob/main/docs/adr/0011-portable-fallback-backend.md). (#63)
- **Scored search results**: `Product::searchRagWithScores()`,
  `Rag::searchWithScores()`, and `RagSearch::searchWithScores()` return
  `RagSearchResult` objects with the model, cosine similarity `score`,
  `distance`, best `chunkIndex`, and the chunk text via `chunk()`. (#65)
- **Automatic embedding** (opt-in): with `eloquent-rag.embedding.auto` /
  `RAG_AUTO_EMBED`, the queued lifecycle sync chains an
  `EmbedRagDocuments` job for documents it changed. (#64)
- `rag:doctor` warns when synced documents have no embeddings yet. (#64)
- **Lifecycle events**: `RagDocumentSynced`, `RagDocumentEmbedded`,
  `RagSyncFailed`, `RagEmbeddingFailed`. (#73)
- **`Rag::fake()`** and a [testing guide](https://github.com/ahmed-nour-dev/eloquent-rag/blob/main/docs/testing.md) for testing
  `HasRag` models without a vector database or embedding provider. (#72)
- **Pivot helpers** `attachRag()`, `detachRag()`, `syncRagRelation()` on
  `HasRag`, and a **`rag:verify`** command that detects documents that
  drifted from their model data (optionally re-syncing them). (#68)
- **`tiktoken` tokenizer driver** backed by the optional
  `yethee/tiktoken` package, for real BPE chunk sizing. (#71)
- CI: a monthly/manual benchmark workflow against real MariaDB and
  PostgreSQL (#66), an allowed-to-fail `laravel/ai` 1.x-dev leg, and a
  weekly scheduled run (#70).
- `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, issue templates, and a
  [version support policy](https://github.com/ahmed-nour-dev/eloquent-rag/blob/main/docs/support-policy.md). (#69, #70)
- Explicit queue retry/backoff/timeout policy for `SyncRagDocument` and
  `ForgetRagDocument` ([ADR-0010](https://github.com/ahmed-nour-dev/eloquent-rag/blob/main/docs/adr/0010-queue-retry-policy.md)). (#55)

### Changed

- **Breaking (minor):** `RagSynchronizer::sync()` returns `bool` (whether
  the document was rewritten) instead of `void`. Code that only calls it is
  unaffected; code that extends or type-checks its return value may need
  updating.
- `composer.json` now allows `laravel/ai` `^0.11.2 || ^1.0`. (#70)
- The embedding-column migration no longer converts the column on plain
  MySQL, which has no usable `VECTOR` type; previously the migration failed
  there on MySQL 8.x. (#63)
- `composer.json`'s `homepage` now points at the real repository. (#69)

### Fixed

- `embed()` skips the provider call when a concurrent `sync()` has already
  invalidated its inputs. (#56)
- `benchmarks/FanoutBenchmarkTest.php` referenced a non-existent model
  class and failed to run. (#66)

## [0.1.0-beta.1] - 2026-09-15

First public beta: model lifecycle sync, declarative dependency tracking
and invalidation with bounded, coalesced fan-out, chunking with ADR-0004
content/configuration hashing, `laravel/ai` embeddings, native vector
search on MariaDB 11.7+ and PostgreSQL+pgvector, and the `rag:doctor`,
`rag:status`, `rag:sync`, `rag:rebuild`, `rag:prune`, `rag:forget`, and
`rag:dependencies` commands.

[Unreleased]: https://github.com/ahmed-nour-dev/eloquent-rag/compare/v0.2.0-beta.1...HEAD
[0.2.0-beta.1]: https://github.com/ahmed-nour-dev/eloquent-rag/compare/v0.1.0-beta.1...v0.2.0-beta.1
[0.1.0-beta.1]: https://github.com/ahmed-nour-dev/eloquent-rag/releases/tag/v0.1.0-beta.1
