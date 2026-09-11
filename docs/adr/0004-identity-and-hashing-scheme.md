# ADR-0004: Document/chunk identity + hashing scheme

**Status:** Proposed

## Context

Sync must be idempotent: re-running it against unchanged source data or
unchanged configuration must do no redundant work (no re-render, no
re-chunk, no re-embed), and it must be safe to re-drive after partial
failure without producing duplicate or orphaned rows.

## Decision

- **Document identity:** `(model_type, model_id)`, unique on `rag_documents`.
  One document row per synced model instance.
- **Chunk identity:** `(document_id, chunk_index)`, unique on `rag_chunks`.
- **Two independent hashes gate rebuild work**, stored on `rag_documents`:
  - `content_hash` — hash of the rendered source content (the model's own
    fields plus all declared relation data per
    [ADR-0002](0002-declarative-dependency-registry.md)).
  - `configuration_hash` — hash of everything that isn't source data but
    still determines the output: the `RagDefinition`, chunking settings
    (`maxTokens`, `overlap`), embedding model identifier, and embedding
    dimensions.
- Sync short-circuits whenever both hashes still match the last-synced
  values — unchanged source data with unchanged config does nothing.

## Consequences

- `rag:rebuild` (Phase 4) can always be re-run safely — it's a versioned,
  hash-checked replacement, never a delete-and-pray operation.
- Changing chunk settings or swapping the embedding model invalidates
  everything automatically via `configuration_hash`, with no separate
  migration flag or manual "please re-sync everything" step required.
- This makes document rendering and chunking's determinism a hard
  requirement, not a nice-to-have: for a given model state and config,
  rendering and chunking must be byte-identical across runs, or the hashes
  are meaningless and staleness detection silently breaks. This is Phase
  1's explicit exit criterion, and it's why Phase 1 is deliberately built
  and tested with zero embeddings, zero queues, and zero DB writes to
  vector columns — it must be provable as a pure function first.
- Staleness comparison (Phase 2) becomes a cheap hash comparison rather
  than a content diff, keeping the lifecycle observers fast on the
  synchronous path.
