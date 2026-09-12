# ADR-0005: Fan-out policy — batching, coalescing, backpressure

**Status:** Confirmed by the Phase 0 spike — see
[docs/spikes/0001-dependency-graph.md](../spikes/0001-dependency-graph.md)

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
- Rapid repeated triggers against the same dependency are **coalesced**
  via an atomic pre-resolution debounce lock (`Cache::add($key, true, $ttl)`
  taken before affected-document resolution runs): the first trigger in the
  window proceeds, every other trigger in the window is a no-op. Multiple
  saves to the same `Category` within a short window collapse into a
  single invalidation pass rather than one pass per save.
- This debounce lock is what prevents the same document from being
  re-queued multiple times across overlapping invalidation passes — it is
  a pre-resolution guard, not a queue-native unique-job feature
  (e.g. `ShouldBeUnique`).

## Consequences

- The coalescing mechanism above was picked and validated empirically by
  the Phase 0 spike (items 5 and 6): an atomic cache-based debounce lock,
  confirmed correct under 10 rapid repeated saves at both 50,000 and
  200,000 simulated dependent documents. It is productionized as-is in
  Phase 2's `SyncRagDocument` job pipeline — no further design work needed
  here, only implementation.
- Batch size is an operational knob, not a hardcoded constant — operators
  can tune it per deployment via the CLI.
- This was the project's central bet: dependency invalidation at scale is
  the one genuinely novel part of the system, and if it hadn't worked
  cleanly it would have changed the schema, the API, and the queue design
  everywhere downstream. That's why it was proven in a throwaway spike
  (Phase 0) before a single line of the real package was written. The
  plan's kill criterion — fan-out can't be bounded without hydrating
  models — was not triggered: reverse lookup returned 38ms at 50,000 docs
  and 179ms at 200,000, IDs only, at every scale tested.
