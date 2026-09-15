# ADR-0010: Queue retry/backoff/timeout policy, and why not ShouldBeUnique

**Status:** Decided (issue #55)

## Context

`SyncRagDocument` and `ForgetRagDocument` declared no `$tries`, `$backoff`,
`$timeout`, or `ShouldBeUnique` — both ran entirely on whatever the
consuming application's queue connection defaulted to. That left the
package's queue behavior undefined rather than deliberately chosen, and two
concrete scenarios needed a real answer rather than an inherited default:

- A model's own `created`/`updated` hook queues `SyncRagDocument` for
  itself (`RagSynchronizer::queue()`) at close to the same time a
  dependency change fans out and queues *another* `SyncRagDocument` batch
  that also contains that same model — both dispatched via `afterCommit()`,
  so they can land on the queue back-to-back.
- Without an explicit `$tries`/`$backoff`, a transient infrastructure
  failure gets whatever the app's default backoff is, which may be
  immediate — fine for a job this cheap, but still undefined behavior this
  package never chose.

`SyncRagDocument::handle()` already catches every exception `sync()` itself
can throw per pair (issue #54) and records it on that pair's document row;
that failure path never reaches Laravel's retry machinery at all. What a
job-level retry policy actually guards against is the batch failing
*outside* that per-pair loop — e.g. a dropped DB connection or a deadlock
before or between pairs — not a bad document. As of this decision, neither
job calls an external embedding provider (`embed()` is only ever invoked
from the CLI path, not from the queued jobs), so both are pure, idempotent
DB reconciliation: `sync()`'s content/configuration-hash short-circuit
(ADR-0004) makes redoing an already-completed pair a no-op, and
`ForgetRagDocument`'s `?->delete()` is a no-op on an already-deleted row.

## Decision

- **`SyncRagDocument`**: `$tries = 3`, `$backoff = [10, 60]`,
  `$timeout = 120`. Three attempts with a short growing delay gives a
  transient DB blip room to clear without hammering it; 120s bounds a
  single attempt at this job's largest batch
  (`config('eloquent-rag.queue.batch_size')`, default 500 pairs of pure
  structural reconciliation) well above its normal cost, so a genuinely
  stuck job is killed and retried instead of parking a worker on it
  indefinitely.
- **`ForgetRagDocument`**: `$tries = 3`, `$backoff = [10, 60]`,
  `$timeout = 60`. Same infrastructure-failure profile as above; a smaller
  timeout because a delete-by-key batch is lighter per pair than a full
  `sync()`.
- **Neither job implements `ShouldBeUnique`** (or a custom dedup key).
  Queue-native uniqueness operates on *dispatch*, keyed off some identity
  derived from the job's constructor arguments — for these jobs that's
  necessarily the batch's pair set (or some hash of it), not an individual
  document. That has two failure modes for the scenario above: a
  fan-out batch and a single-model `queue()` dispatch for the same
  document don't share a batch shape, so a naive unique key wouldn't even
  catch the collision it's meant to catch; and if the key were instead
  derived per-model in a way that did catch it, a legitimately newer
  dispatch would be **dropped**, not deferred, whenever an older, still-
  pending dispatch for an overlapping key hasn't run yet — exactly the
  supersede-not-drop hazard raised in #55. `ShouldBeUnique` also only
  applies at the moment of dispatch, so it does nothing for two batches
  that are both already on the queue by the time either runs — which is
  precisely the back-to-back scenario this ADR was raised to address.
  Coalescing repeated dependency-triggered fan-out is already handled
  correctly, and at the right point (pre-resolution, before any batch is
  even built), by ADR-0005's debounce lock. `sync()`'s own idempotency
  means any dispatch that duplicate anyway is wasteful, never incorrect —
  the same property that makes retrying a partially-completed batch safe
  above.

## Consequences

- Both jobs now have an explicit, package-owned retry/timeout policy
  instead of inheriting whatever the host application's queue connection
  happens to default to.
- If a future change makes `SyncRagDocument` reachable to `embed()` (an
  external, potentially rate-limited embedding provider call), this policy
  must be revisited — `$backoff` in particular was chosen for pure DB
  reconciliation, not for absorbing provider rate limits or timeouts.
- Duplicate dispatches for the same document remain possible by design
  (back-to-back `queue()` and fan-out, or a retried batch) and are handled
  by `sync()`'s existing idempotency (ADR-0004), not by queue-level
  dedup — consuming applications should not expect
  `SyncRagDocument`/`ForgetRagDocument` to ever refuse a dispatch.
