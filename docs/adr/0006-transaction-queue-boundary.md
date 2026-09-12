# ADR-0006: Transaction/queue boundary — when a job gets dispatched relative to commit

**Status:** Confirmed by the Phase 0 spike — see
[docs/spikes/0001-dependency-graph.md](../spikes/0001-dependency-graph.md)

## Context

Model lifecycle observers (`created`, `updated`, `deleted`, `restored`,
pivot `attach`/`detach`) are the trigger for RAG sync and invalidation. If a
job is dispatched from inside an observer while the enclosing database
transaction is still open, two failure modes are possible: the job can run
against data that later gets rolled back and never actually existed, or the
job can race the transaction and read a pre-commit, inconsistent state.

Separately, some write paths never fire model events at all —
`Category::where(...)->update()` (mass update) bypasses Eloquent observers
entirely, so no invalidation is triggered by default. The same is true of
bulk inserts (`DB::table(...)->insert()`).

This risk is concrete specifically for queue backends that are **not**
part of the app's own database transaction — Redis, SQS, Beanstalkd, or a
`database`-driver queue connection that is physically separate from the
app's default connection (the normal, correct setup). If the queue happens
to share the exact same database connection as the app, a job dispatched
without after-commit protection can incidentally get swept into — and
rolled back with — the enclosing transaction anyway, which can mask this
issue in a naive same-connection test setup. The protection this ADR
mandates is what makes the behavior correct and connection-topology
independent, rather than correct by accident. (Confirmed by the Phase 0
spike: proving this cleanly required deliberately separating the queue's
database connection from the app's, per
[docs/spikes/0001-dependency-graph.md](../spikes/0001-dependency-graph.md#2-the-aftercommit-protection-only-bites-when-the-queue-isnt-the-same-transactional-resource-as-the-app-db).)

## Decision

- Invalidation and sync jobs are dispatched **only after the enclosing
  transaction commits** (Laravel's after-commit dispatch), never from
  inside an open transaction. Verified explicitly by Phase 0 spike item 9,
  productionized in Phase 2's `SyncRagDocument` job.
- Mass-update paths that bypass Eloquent events are **not silently
  covered**. This is a documented limitation, not a bug: developers using
  `Model::where(...)->update()` must call the explicit `Rag::invalidate()`
  escape hatch themselves. Phase 0 spike item 10 exists to reach and record
  this decision, not to try to intercept mass updates automatically.

## Consequences

- No sync or invalidation work is ever performed against data from a
  transaction that might still roll back.
- The mass-update limitation must be prominently documented — in
  `docs/principles.md`'s non-goals framing, in the main docs (Phase 5), and
  in the rebuild/migration guide — since it's an easy trap for a developer
  who assumes all writes are automatically tracked.
- `Rag::invalidate()` must exist as a first-class, documented public API
  from Phase 2 onward, not an internal-only helper, since it's the intended
  and sanctioned way to cover mass-update paths.
- `rag:doctor` (Phase 4) should be able to surface orphaned/stale
  dependency rows that likely stem from missed mass-update invalidation,
  even though it can't detect the root cause automatically.
