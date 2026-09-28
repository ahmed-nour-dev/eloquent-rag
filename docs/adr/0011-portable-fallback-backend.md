# ADR-0011: An opt-in portable fallback for development and small data

**Status:** Decided (issue #63). Amends the "no PHP-side fallback"
consequence of [ADR-0003](0003-backend-support-matrix.md).

## Context

ADR-0003 limits `embed()` and search to MariaDB 11.7+ and
PostgreSQL+pgvector and rules out a PHP-side cosine-similarity fallback
for plain MySQL. That's still the right line for **production** vector
search. It also means most Laravel apps, which run on plain MySQL, can't
try the package end to end locally, in CI, or in a demo without first
provisioning a new database server. Plain MySQL 8.x couldn't even *run
the migrations*: the embedding column conversion called
`$table->vector()` on a server that has no `VECTOR` type.

Packages in the same space solve this with a portable driver that stores
embeddings as JSON and ranks in PHP.

## Decision

- Add `config('eloquent-rag.portable_fallback.enabled')` (env
  `RAG_PORTABLE_FALLBACK`), **off by default**.
- When it's on and the RAG connection is SQLite or a real MySQL server
  (`VectorBackendCapability::isPortableFallbackCandidate()`), `embed()`
  stores embeddings as JSON text (what `AsVector` already writes on those
  grammars) and `RagSearch` ranks in PHP: it streams every embedded chunk
  of the searched model type (`lazyById`, keeping only each document's
  best chunk in memory), computes cosine distance, and applies the same
  best-chunk-per-document ranking, `limit`, `minSimilarity`, and `scope()`
  semantics as the native path.
- The `rag_chunks.embedding` vector-column migration skips plain MySQL
  (as it already skipped SQLite), leaving the text column in place.
- A natively supported backend always uses native search, whether or not
  the fallback is enabled. A below-floor MariaDB or a PostgreSQL without
  `pgvector` is **not** a fallback candidate: `AsVector` writes MariaDB
  vectors through `vec_fromtext()`, which old servers lack, and the right
  fix for missing `pgvector` is installing it.
- `rag:doctor` downgrades the vector-backend check to a `WARN` that says
  the fallback is in use and is not for production.

## Consequences

- The "no PHP-side cosine-similarity fallback will ever be built"
  consequence in ADR-0003 no longer holds as written. What still holds:
  there is no supported *production* path on backends without native
  vector search. The fallback is labeled development/small-data only
  everywhere it surfaces (config comment, exception message, docs,
  `rag:doctor`).
- Search cost on the fallback is linear in the number of embedded chunks
  of the searched model type; there is no index, and none is planned.
- The package's own SQLite test suite can now exercise `embed()` and search
  end to end, not only their rejection paths.
