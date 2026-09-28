# Principles

## Core principle

> This package does not implement vector search. It integrates Eloquent
> models with Laravel's AI and vector capabilities, providing model
> lifecycle synchronization, dependency tracking, invalidation, chunk
> management, and rebuild tooling.

Every feature proposal is tested against this sentence. If it isn't
lifecycle sync, dependency tracking, invalidation, chunk management, or
rebuild tooling, it doesn't belong here — see [ADR-0001](adr/0001-package-boundary.md).

## Explicit non-goals for v1

- **No custom `EmbeddingProvider` abstraction.** Laravel AI owns embeddings.
- **No custom `VectorStore` abstraction.** Laravel's native vector API owns
  storage and search.
- **No production vector search on backends without native support.**
  MySQL and SQLite get only an opt-in, clearly labeled development/small-data
  [portable fallback](backend-support.md#portable-fallback), never a
  supported production path — see [ADR-0003](adr/0003-backend-support-matrix.md)
  and [ADR-0011](adr/0011-portable-fallback-backend.md).
- **No automatic/magical Eloquent relationship discovery.** Dependencies are
  declarative, always — see [ADR-0002](adr/0002-declarative-dependency-registry.md).
- **No agent framework, chat UI, or prompt management.** Out of scope,
  full stop.

Scope creep has to argue against this document, not against a person in a
PR review.

## Related decisions

See [docs/adr/](adr/) for the full set of Architecture Decision Records this
package is built on:

- [ADR-0001](adr/0001-package-boundary.md) — Package boundary
- [ADR-0002](adr/0002-declarative-dependency-registry.md) — Declarative dependency registry
- [ADR-0003](adr/0003-backend-support-matrix.md) — Backend support matrix
- [ADR-0004](adr/0004-identity-and-hashing-scheme.md) — Identity + hashing scheme
- [ADR-0005](adr/0005-fanout-policy.md) — Fan-out policy
- [ADR-0006](adr/0006-transaction-queue-boundary.md) — Transaction/queue boundary
- [ADR-0007](adr/0007-pivot-change-detection.md) — Pivot change detection
- [ADR-0008](adr/0008-vector-indexing-strategy.md) — Vector indexing strategy
- [ADR-0009](adr/0009-tokenizer-abstraction.md) — Tokenizer abstraction
- [ADR-0010](adr/0010-queue-retry-policy.md) — Queue retry policy
- [ADR-0011](adr/0011-portable-fallback-backend.md) — Portable fallback backend
