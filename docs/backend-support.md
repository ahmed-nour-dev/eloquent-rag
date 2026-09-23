# Backend support

## The matrix

| Target | Supported | Requirement |
|---|---|---|
| MariaDB 11.7+ | ✅ | Laravel 13.29+ |
| PostgreSQL + `pgvector` extension | ✅ | Laravel 13.x |
| Plain MySQL 8.x | ❌ | No native vector backend — not supported, no fallback |
| Pinecone / Qdrant / Weaviate | ❌ | Out of scope unless demonstrated demand emerges |

This package relies entirely on Laravel's own native vector query builder
(`whereVectorDistanceLessThan`, `orderByVectorDistance`,
`selectVectorDistance`) and native vector column type
(`Blueprint::vector()`, the `AsVector` cast) for *production* vector
search — see [ADR-0001](adr/0001-package-boundary.md) and
[ADR-0003](adr/0003-backend-support-matrix.md). If your backend isn't in
the table above, this package doesn't do production-grade vector search
for it — it can still track dependencies and render documents (that part
of the package works on any Eloquent-compatible connection, including
SQLite or plain MySQL) — but `embed()` and search will refuse to run,
**unless** you opt into the development/small-scale fallback below.

## Fallback backend (opt-in, dev/small-scale)

Set `config('eloquent-rag.fallback.enabled')` to `true` (off by default)
to let `embed()`/`searchRag()`/`Rag::search()` run against a connection
with no native vector backend at all — SQLite, or plain/unconfigured
MySQL. See [ADR-0011](adr/0011-portable-fallback-backend.md) for the full
decision record.

Storage doesn't change: `RagChunk::embedding` already JSON-encodes on
these drivers via Laravel's `AsVector` cast. What changes is search
ranking — instead of Laravel's native vector query builder,
`RagSearch` pulls every candidate chunk for the searched model type
(after any `scope()` filter) into PHP and computes cosine similarity
against each one directly.

This is explicitly **not** a substitute for a supported backend in
production:

- **No index.** Every search is a full scan of the candidate chunks —
  there is no equivalent to pgvector's HNSW index or MariaDB's `VECTOR
  INDEX` here.
- **Bounded, not just slow.** `config('eloquent-rag.fallback.max_candidate_chunks')`
  (default 5000) caps how many chunks a single `search()` call will scan.
  Exceeding it throws `FallbackCandidateLimitExceeded` with an actionable
  message — a clear failure instead of the fallback quietly getting slower
  as your data grows. Raise the config value only if you understand the
  cost of doing so.
- **Narrowly scoped.** The fallback only engages for a driver with no
  native vector backend at all. A genuinely misconfigured *supported*
  backend — MariaDB below the 11.7 floor, or Postgres missing the
  `pgvector` extension — still hard-fails exactly as before: the fix
  there is to upgrade the server or run `CREATE EXTENSION vector`, not to
  quietly fall back.
- **`rag:doctor` reports it as `[WARN]`**, not `[PASS]` — see below.

## Why the check is stricter than Laravel's own

Laravel's `MariaDbGrammar`/`PostgresGrammar::supportsVectorDistance()` both
report `true` unconditionally — for *any* MariaDB version (even ones below
11.7 that lack the `VECTOR` column type) and *any* Postgres (pgvector
extension installed or not). Trusting that flag alone would let an
unsupported setup pass a naive check and then fail with a confusing SQL
error the first time it actually tries to run a vector query.
`VectorBackendCapability` goes further: for MariaDB it queries the real
connected server version; for Postgres it checks whether the `pgvector`
extension is actually installed (`pg_extension`). This is what makes
`rag:doctor` (below) trustworthy rather than a check that only catches the
easy half of the problem.

## Vector indexing

As `rag_chunks` grows, an unindexed vector-distance query
(`orderByVectorDistance()`/`whereVectorDistanceLessThan()`, which this
package's `searchRag()` always uses) degrades to a full table scan. The
indexing story is asymmetric between the two supported backends —
see [ADR-0008](adr/0008-vector-indexing-strategy.md) for the full
reasoning.

| | PostgreSQL + pgvector | MariaDB |
|---|---|---|
| Index created automatically? | ✅ Yes — a migration adds an HNSW index | ❌ No — see limitation below |
| Index type | HNSW (approximate nearest neighbor) | MariaDB's native HNSW-family `VECTOR INDEX` |
| Distance/operator | `vector_cosine_ops` | `DISTANCE=cosine` |
| Handles a nullable column? | ✅ `NULL` (and zero) vectors are simply excluded from the graph | ❌ The indexed column **must** be declared `NOT NULL` |
| Limitations | Rebuilding a large HNSW index is memory- and time-intensive (`maintenance_work_mem`); combining a filter predicate with indexed vector search requires the `ORDER BY`/`LIMIT` shape pgvector expects to actually use the index | Only one vector index per table; requires `NOT NULL`, which conflicts with this package's decoupled `sync()`/`embed()` lifecycle (see below) |
| Recommended strategy at scale | Nothing to do — the index ships with the package. If a single global `maintenance_work_mem` build becomes a bottleneck at very large row counts, build with `SET maintenance_work_mem` raised for that session first | Accept the full-scan cost, or make the `NOT NULL` trade-off yourself outside this package (see below) — this package will not force it on you |

### Why MariaDB doesn't get an automatic index

MariaDB's `VECTOR INDEX` requires the indexed column to be declared
`NOT NULL`. `rag_chunks.embedding` is nullable by design: `sync()` creates
the chunk row, and `embed()` — often run later via the queue — populates
`embedding` afterward (`RagSearch` already filters these out with
`whereNotNull('rag_chunks.embedding')`). Making the column `NOT NULL`
to satisfy MariaDB's index would mean either embedding synchronously
inside `sync()` (removing the queued fan-out/embed separation ADR-0005 and
ADR-0006 established, for every consumer of this package) or backfilling
a placeholder vector — which MariaDB, unlike pgvector, does **not**
exclude from the index automatically, so every not-yet-embedded chunk
would pollute the index unless filtered back out by a non-vector `WHERE`
clause, a combination MariaDB's own documentation treats as a distinct
"hybrid search" concern with its own caveats.

If you need indexed vector search on MariaDB at real scale, you must make
that trade-off explicitly and outside this package (e.g., embedding
synchronously in your own `sync()` hook, or maintaining a separate
`NOT NULL`/indexed projection table). `rag:doctor` (below) reports this as
a `WARN`, not a `FAIL` — it's a known performance cost, not a correctness
problem.

## `rag:doctor`

```bash
php artisan rag:doctor
```

Run this before indexing a single row on a new install, and again after
any infrastructure change (Laravel upgrade, database migration, changing
`config('eloquent-rag.embedding.dimensions')`). It checks, in order:

| Check | Level | What it catches |
|---|---|---|
| Laravel version | FAIL | Below the 13.29 floor |
| Vector backend | FAIL, or WARN if the [opt-in fallback](#fallback-backend-opt-in-devsmall-scale) is enabled and engages | Wrong driver, MariaDB below 11.7, or Postgres missing `pgvector` — the real checks above, not just Laravel's grammar flag. MariaDB-below-floor and missing-`pgvector` always stay FAIL even with the fallback enabled — see ADR-0011 |
| Embedding dimension | FAIL | `rag_chunks.embedding`'s actual declared vector size doesn't match `config('eloquent-rag.embedding.dimensions')` (skipped if the backend check above already failed) |
| Vector index | WARN | No indexed vector search available on this connection — always on MariaDB (see [Vector indexing](#vector-indexing)), or on Postgres if the expected index is missing (skipped if the backend check above already failed) |
| Queue driver | WARN | `queue.default` is `sync` — fan-out will run inline instead of batched, fine locally, not recommended in production |
| Orphaned dependency rows | WARN | `rag_dependencies` rows pointing at a `document_id` that no longer exists — should be impossible given the FK cascade; flags a connection with FK enforcement disabled |
| Failed documents | WARN | `rag_documents` rows with `status = 'failed'`, with their recorded `last_error` |
| Failed jobs | WARN | A non-zero count in Laravel's own `failed_jobs` table, as a general queue-health signal |

FAIL-level checks produce a non-zero exit code — safe to wire into a
deploy step or CI gate. WARN-level checks are operational hygiene, not
blockers.

## Composer constraint discipline

`composer.json` pins `illuminate/database: ^13.29`, not `^13.0`. The
native vector query builder API is genuinely new (merged via Laravel PR
#61250, with a follow-up SQL fix in PR #61337 that only landed in 13.29.0 —
13.27.0, the previous released version, still emits
`vec_distance_cosine(`embedding`, ?)` without the required
`vec_fromtext(...)` wrapper for MariaDB), so this constraint is treated as
pinned to unstable/settling code: it gets widened only after a new point
release has been explicitly run through this package's own CI matrix
(`.github/workflows/tests.yml`), not proactively.
