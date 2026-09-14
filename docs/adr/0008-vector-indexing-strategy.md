# ADR-0008: Vector indexing strategy — automatic on pgvector, documented-only on MariaDB

**Status:** Accepted

## Context

[Issue #10](https://github.com/ahmed-nour-dev/eloquent-rag/issues/10) asks
this package to define, test, and document a vector indexing strategy: as
`rag_chunks` grows, an unindexed `orderByVectorDistance()` /
`whereVectorDistanceLessThan()` query (see
[RagSearch](../../src/RagSearch.php)) degrades to a full table scan.

Laravel's schema builder exposes `Blueprint::vectorIndex($column, $name =
null)`, which compiles to two very different DDL statements depending on
the connected driver's grammar:

- **PostgreSQL/pgvector**: `create index ... using hnsw (embedding
  vector_cosine_ops)` — an HNSW approximate-nearest-neighbor index.
- **MariaDB**: `alter table ... add vector index (embedding) M=6
  DISTANCE=cosine` — MariaDB's own HNSW-family native vector index.

Both default to cosine, matching the cosine distance this package's
grammar always compiles
(`vec_distance_cosine()`/`<=>` — see `MariaDbGrammar`/`PostgresGrammar
::compileVectorDistanceExpression()`; this package never asks for a
different distance function), so no operator-class override is needed on
either backend.

`rag_chunks.embedding` is nullable by design, not by oversight: `sync()`
creates the chunk row first, and `embed()` — run separately, often via the
queue (ADR-0005/0006) — populates `embedding` afterward. `RagSearch`
already accounts for this with an explicit
`whereNotNull('rag_chunks.embedding')` before ranking. This nullability is
where the two backends diverge sharply:

- **pgvector tolerates it.** Per pgvector's own documentation, "`NULL`
  vectors are not indexed (as well as zero vectors for cosine distance)" —
  rows with a `NULL` embedding are simply omitted from the HNSW graph, with
  no schema change required. This is exactly the semantics `RagSearch`
  already assumes.
- **MariaDB does not.** MariaDB's own documentation states plainly: "there
  can be only one vector index in the table, and the indexed vector
  column must be `NOT NULL`" (confirmed directly against
  <https://mariadb.com/docs/server/reference/sql-structure/vectors/create-table-with-vectors>,
  and independently against the `ALTER TABLE ... ADD VECTOR INDEX` form).
  This is a hard DDL-level rejection, not a data-content check — MariaDB
  will refuse to add the index at all while the column is nullable,
  regardless of whether any row currently holds a `NULL`.

Working around MariaDB's constraint would mean one of:

1. Declaring `embedding` `NOT NULL` and backfilling a placeholder vector —
   but MariaDB, unlike pgvector, does **not** exclude placeholder/zero
   vectors from the index automatically, so every not-yet-embedded chunk
   would pollute the HNSW graph and would need to be filtered back out at
   query time by a non-vector `WHERE` predicate. MariaDB's own docs flag
   combining a filter predicate with indexed vector search as a distinct
   "hybrid search" concern with its own caveats, not something the index
   handles for free.
2. Embedding synchronously inside `sync()` so `embedding` is never `NULL`
   — but that removes the queued fan-out/embed separation ADR-0005 and
   ADR-0006 deliberately established, for every consumer of this package,
   just to satisfy one backend's index constraint.

Neither is something this package can decide on a user's behalf inside a
migration that runs unconditionally on `php artisan migrate`.

## Decision

- **PostgreSQL/pgvector**: add the HNSW vector index automatically, in a
  new migration
  (`database/migrations/2026_01_04_000001_add_vector_index_to_rag_chunks_table.php`),
  guarded to run only when the connected driver is `pgsql`. This is safe
  and requires no schema trade-off, per the NULL-exclusion behavior above.
- **MariaDB**: do **not** add a vector index automatically. `rag_chunks`
  keeps its plain (unindexed) `VECTOR` column on MariaDB, and
  `orderByVectorDistance()` queries run as a full table scan there. This
  is a known, documented limitation (see
  [docs/backend-support.md](../backend-support.md#vector-indexing)), not a
  bug — forcing `NOT NULL` on `embedding` to satisfy MariaDB's index
  requirement would break the sync/embed decoupling for every consumer of
  this package, not just MariaDB ones.
- `rag:doctor` reports the real per-backend index status (PASS with the
  index definition on pgvector; a WARN explaining the NOT NULL conflict on
  MariaDB) so this trade-off is visible before a deployment scales up, not
  discovered by a slow query in production.
- Plain MySQL and any other driver: no vector index command is issued
  (`VectorBackendCapability` already rejects the connection entirely
  before either `embed()` or search would run against it).

## Consequences

- MariaDB deployments with a large `rag_chunks` table pay for a full scan
  on every search. Operators who need indexed MariaDB vector search must
  make the `NOT NULL` trade-off explicitly and manually (e.g., embedding
  synchronously in their own `sync()` hook, or maintaining a separate
  NOT NULL/indexed projection outside this package) — this package will
  not do it for them by default. See
  [docs/backend-support.md](../backend-support.md#vector-indexing) for the
  practical guidance given to operators in this position.
- If a future MariaDB release relaxes the `NOT NULL` requirement, or this
  package's chunk lifecycle changes such that `embedding` can be populated
  synchronously without regressing ADR-0005/0006, this decision should be
  revisited.
- Changing `config('eloquent-rag.embedding.dimensions')` (the `rag:rebuild`
  path) does not need special handling here: MariaDB has no automatic
  index to drop and recreate, and Postgres's HNSW index has no stored
  dimension of its own to invalidate — but the underlying `vector(N)`
  column itself is still Postgres's own hard dimension boundary, and
  `rag:doctor`'s existing dimension check (ADR unrelated to this one)
  continues to catch a mismatch there.
