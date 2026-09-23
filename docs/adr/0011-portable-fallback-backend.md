# ADR-0011: Opt-in PHP-side fallback backend for SQLite/plain MySQL

**Status:** Decided (issue #62)

## Context

[ADR-0001](0001-package-boundary.md) and [ADR-0003](0003-backend-support-matrix.md)
correctly restrict *production* vector search to MariaDB 11.7+ and
PostgreSQL+pgvector, and state that no PHP-side cosine-similarity fallback
will ever be built. That's a defensible constraint for production, but it
also shrinks the package's addressable audience to almost nobody: most
Laravel apps run on plain MySQL, and local development commonly runs on
SQLite. Neither had any path to trying `embed()`/search at all, not even
for a handful of rows in a dev environment.

Storage already degrades correctly on these drivers without any change:
`RagChunk::embedding` uses Laravel's `AsVector` cast, which JSON-encodes
the vector on any non-native driver (see
`database/migrations/2026_01_02_000001_convert_rag_chunks_embedding_to_vector_column.php`,
which deliberately leaves `embedding` a plain nullable text column on
SQLite). What refuses to run is purely the *search-ranking* half:
`VectorBackendCapability::ensureSupported()` (called from
`RagSynchronizer::embed()` and `RagSearch::search()`) and the native
vector query builder calls in `RagSearch` (`whereVectorDistanceLessThan`,
`selectVectorDistance`), which only exist on MariaDB/Postgres grammars.

## Decision

An opt-in, off-by-default PHP-side fallback now exists:
`config('eloquent-rag.fallback.enabled')` (default `false`). Nothing about
this package's behavior changes unless a consumer explicitly sets it.

When enabled, `VectorBackendCapability::ensureUsable()` no longer throws
for a connection whose driver has **no native vector backend at all** —
SQLite, or plain/unconfigured MySQL (exactly the case
`UnsupportedVectorBackend::unsupportedDriver()` covers). `RagSearch` then
ranks candidate chunks by computing cosine similarity in PHP
(`Support/CosineDistance`) against each chunk's already-stored embedding,
instead of using the native vector query builder. `RagSynchronizer::embed()`
needed no change beyond the same relaxed gate — its write path was already
driver-agnostic.

This deliberately does **not** extend to a genuinely misconfigured
*supported* backend — MariaDB below the 11.7 floor, or Postgres missing
the `pgvector` extension. Those still hard-fail exactly as ADR-0003
specified: the fix there is to upgrade the server or run
`CREATE EXTENSION vector`, not to silently degrade to a slow PHP path.
This distinction is encoded as `UnsupportedVectorBackend::$fallbackEligible`,
true only for the "driver has no native vector support" case.

Guardrails, all in service of ADR-0003's original "never a confusing
failure" principle:

- **No index, no query planner.** Every matching chunk for the searched
  model type (after `scope()`) is pulled into PHP and compared one at a
  time. `config('eloquent-rag.fallback.max_candidate_chunks')` (default
  5000) is a hard ceiling — exceeding it throws
  `FallbackCandidateLimitExceeded` with an actionable message, rather than
  the fallback silently getting slower and slower as data grows.
- **`rag:doctor` reports it as `[WARN]`, never `[FAIL]`**, when
  successfully engaged — explicitly labeled "not recommended for
  production." It still `[FAIL]`s exactly as before when the fallback is
  disabled or ineligible.
- **Explicitly labeled everywhere**: the config comment, the doctor
  output, and `UnsupportedVectorBackend`'s message all say this is a
  development/small-scale convenience, not a production vector store.

## Consequences

- Amends [ADR-0001](0001-package-boundary.md)'s Consequences ("No custom
  `EmbeddingProvider` or `VectorStore` abstraction is ever introduced")
  and [ADR-0003](0003-backend-support-matrix.md)'s Consequences ("No
  PHP-side cosine-similarity fallback will ever be built") — narrowly, for
  this one opt-in, off-by-default, non-production case. The broader
  boundary both ADRs establish still stands: no pluggable `VectorStore`
  abstraction, no support for arbitrary third-party backends, no attempt
  to make this fast or indexed. `docs/principles.md`'s non-goal bullet is
  amended the same way.
- A genuinely misconfigured supported backend still hard-fails — the
  fallback is not an escape hatch from fixing a fixable setup.
- `rag_documents`/`rag_chunks`/`rag_dependencies` sync/dependency-tracking
  already worked standalone on any Eloquent-compatible connection
  (including plain MySQL/SQLite) before this ADR — that half of issue #62
  was a documentation confirmation, not new work; see
  `docs/backend-support.md`.
- New files: `Support/CosineDistance` (pure cosine distance, same 0.0–2.0
  convention as Laravel's native distance so both ranking paths are
  interchangeable), `Exceptions/FallbackCandidateLimitExceeded`.
  `VectorBackendCapability::ensureUsable()` is the single gate both
  `RagSynchronizer::embed()` and `RagSearch::search()` now call instead of
  the always-throwing `ensureSupported()`.
