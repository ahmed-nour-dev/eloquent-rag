# ADR-0001: Package boundary — sync layer above Laravel AI, never an AI SDK

**Status:** Proposed

## Context

Eloquent RAG needs a hard boundary against scope creep. The RAG/vector-search
space invites building "just one more" abstraction — an embedding provider
interface, a vector store interface, a search DSL. Laravel AI and Laravel's
native vector column/query APIs already own those concerns. Without an
explicit boundary, this package drifts into re-implementing (and then
maintaining) an AI SDK.

## Decision

Eloquent RAG is a synchronization layer that sits **above** Laravel AI and
Laravel's native vector capabilities. It owns:

- Model lifecycle synchronization (create/update/delete/restore → document sync)
- Dependency tracking and invalidation
- Chunk management
- Rebuild tooling and operability (CLI)

It explicitly does not own, and will never grow:

- Embedding generation (Laravel AI's job)
- Vector storage/search primitives (Laravel's native vector API's job)
- Agent orchestration, chat UI, or prompt management

This is the verbatim principle for the README:

> This package does not implement vector search. It integrates Eloquent
> models with Laravel's AI and vector capabilities, providing model
> lifecycle synchronization, dependency tracking, invalidation, chunk
> management, and rebuild tooling.

## Consequences

- No custom `EmbeddingProvider` or `VectorStore` abstraction is ever
  introduced (see `docs/principles.md` non-goals).
- If Laravel AI or Laravel's vector API is insufficient for something this
  package needs, the fix is to contribute upstream — not to build a parallel
  abstraction here.
- The package's capability ceiling is bounded by the underlying ecosystem's
  maturity. This is a deliberate trade documented in
  [ADR-0003](0003-backend-support-matrix.md), not an oversight.
- Every future feature proposal can be tested against this boundary: "is
  this sync/tracking/tooling, or is it embeddings/storage/agent behavior?"
  The latter gets rejected on sight.
