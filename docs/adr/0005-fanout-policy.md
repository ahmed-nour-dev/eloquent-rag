# ADR-0005: Fan-out policy — batching, coalescing, backpressure

**Status:** Proposed — highest-risk ADR, subject to the Phase 0 kill criterion

## Context

A single change to a widely-referenced model can affect a huge number of
dependent documents — e.g. renaming a `Category` used by 50,000+ `Product`
documents, or detaching/reattaching a `Feature` shared across a similar
fan-out. Naively dispatching one invalidation job per affected document is
untenable: a single category rename must not produce 72,000 individual
jobs. Rapid repeated changes to the same dependency (e.g. several quick
saves while editing a `Category`) must also not each trigger a full,
independent invalidation pass.

## Decision

- Fan-out resolution produces **document IDs only** — dependent documents
  are never hydrated as models during invalidation, per the reverse-lookup
  design in [ADR-0002](0002-declarative-dependency-registry.md).
- Affected IDs are dispatched in **bounded batches** (default ~500 per job,
  tunable via `rag:sync --chunk`), not one job per document.
- Rapid repeated triggers against the same dependency are **coalesced**:
  multiple saves to the same `Category` within a short window collapse into
  a single invalidation pass rather than one pass per save.
- Queue-level deduplication prevents the same document from being
  re-queued multiple times across overlapping invalidation passes.

## Consequences

- The exact coalescing mechanism (debounce window vs. dedup key vs.
  something else) is deliberately left open here and settled empirically
  by the Phase 0 spike (items 5 and 6), then productionized in Phase 2's
  `SyncRagDocument` job pipeline.
- Batch size is an operational knob, not a hardcoded constant — operators
  can tune it per deployment via the CLI.
- This is the project's central bet: dependency invalidation at scale is
  the one genuinely novel part of the system, and if it doesn't work
  cleanly it changes the schema, the API, and the queue design everywhere
  downstream. That's why it's proven in a throwaway spike (Phase 0) before
  a single line of the real package is written, and why the plan's kill
  criterion is anchored here: if fan-out can't be bounded without
  hydrating models, stop and redesign before Phase 1.
