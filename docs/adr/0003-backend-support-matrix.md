# ADR-0003: Supported backends + Laravel 13.29 floor, MySQL exclusion rationale

**Status:** Proposed

## Context

v1 needs a defensible, narrow backend support matrix rather than a "best
effort, works on whatever" posture. Laravel's native vector query builder
API (the mechanism this package relies on per
[ADR-0001](0001-package-boundary.md)) merged for MariaDB in PR #61250
(2026-08-20), with a follow-up SQL fix in PR #61337 merged 2026-08-25 and
first released in v13.29.0. That surface is new and still settling — the
floor is pinned to 13.29.0, not 13.27.0, precisely because 13.27.0 (the
version PR #61250 first shipped in) compiles the MariaDB vector distance
expression as `vec_distance_cosine(`embedding`, ?)`, missing the
`vec_fromtext(...)` wrapper MariaDB's native vector functions require to
parse the bound parameter — confirmed by pinning this package's CI matrix
to that exact version and watching `VectorGrammarSqlTest` fail. Plain
MySQL 8.x has no native vector backend under this API at all.

## Decision

| Target | v1 | Requirement |
|---|---|---|
| MariaDB 11.7+ | Supported | Laravel **13.29+** (vector query builder API, with PR #61337's MariaDB SQL fix) |
| PostgreSQL + pgvector | Supported | Laravel 13.x |
| Plain MySQL 8.x | Not supported | No native vector backend — explicitly excluded, not a fallback candidate |
| Pinecone / Qdrant / Weaviate | Not supported | Post-v1 only, and only on demonstrated demand |

Composer constrains `illuminate/database: ^13.29`, not `^13.0`. This
constraint is treated as pinned to unstable/new code: it is widened only
after each subsequent point release has been explicitly tested against this
package's integration suite.

## Consequences

- No PHP-side cosine-similarity fallback will ever be built to make MySQL
  8.x "work anyway" — that would violate the non-goal against reimplementing
  vector search (see [ADR-0001](0001-package-boundary.md)) and would produce
  a degraded, unscalable experience the package doesn't want to be
  responsible for supporting.
- A user on unsupported infrastructure (MySQL, or MariaDB/Laravel below the
  floor) must get a clear, actionable failure at `rag:doctor` / boot time —
  never a confusing SQL error surfaced mid-queue-job. This is Phase 4's
  central exit criterion.
- Because this package depends on a genuinely new upstream surface, every
  Laravel 13.x point release touching vector grammar must be re-verified in
  CI before the composer constraint is loosened. PR #61337's diff must be
  read and understood before building anything on the query methods it
  touched (`whereVectorDistanceLessThan`, `orderByVectorDistance`,
  `selectVectorDistance`).
- Expanding to other vector backends (Pinecone/Qdrant/Weaviate) is
  explicitly deferred and requires demonstrated demand — it is not a
  roadmap default.
