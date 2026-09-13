# Spike 0001 — Dependency graph

**Status:** Complete — **GO**

**Plan reference:** `.orca/drops/eloquent-rag-build-plan.md`, Phase 0 (lines 55-97)

## Verdict

All of items 1-9 pass cleanly, at both 50,000 and 200,000 simulated
dependent documents. Item 10 has a decided, documented answer. No kill
criterion was triggered: reverse lookup never needed runtime relationship
walking, and fan-out stayed ID-only and bounded at every scale tested.

**Recommendation: proceed to Phase 1.** Two follow-up items are flagged
below for ADR amendment (not redesign) before Phase 2, plus one new
decision point Phase 2 needs to make that isn't covered by any existing ADR.

## Environment

- Laravel **13.31.0** (installed fresh via `composer create-project
  laravel/laravel`; comfortably above the 13.29 floor in
  [ADR-0003](../adr/0003-backend-support-matrix.md)).
- PHP 8.5.9.
- **SQLite**, not MariaDB/pgvector. Phase 0 tests dependency-graph
  mechanics only — no vector column, no embedding, no search touches this
  spike at all, so the backend choice in ADR-0003 is irrelevant here.
  Using SQLite avoided needing credentials for the shared MySQL instance
  in the sandbox, which is unrelated to this project.
- Queue: Laravel's `database` driver, deliberately configured on a
  **second, physically separate SQLite connection** from the app's default
  connection (see Item 9 below for why this mattered).
- Composer wasn't preinstalled in the environment; installed
  `composer.phar` locally via the official installer + published SHA-384
  hash verification (no sudo, no system-wide install).
- Scratch app lives entirely outside this repo, in the session scratchpad.
  Nothing under it was committed here, per the plan's "deliberately
  throwaway" instruction.

## Fixture domain

Exactly as specified: `Product` belongsTo `Category`, belongsTo `Brand`,
belongsToMany `Feature`.

## Schema that actually resulted

Matches the Phase 1 draft and [ADR-0004](../adr/0004-identity-and-hashing-scheme.md)
closely, plus one spike-only observability table:

```
rag_documents      (id, model_type, model_id, version, content_hash,
                     configuration_hash, status, synced_at, timestamps)
                    unique(model_type, model_id)

rag_dependencies   (id, document_id, dependency_type, dependency_id, timestamps)
                    index(dependency_type, dependency_id)  <- confirmed hot path
                    index(document_id)
                    FK document_id -> rag_documents, cascade on delete

rag_invalidation_log   (spike-only: records every batch a job actually
                         processed, so tests can assert without a live
                         queue worker attached)
```

No changes needed to the schema shape already drafted in ADR-0004 or the
plan's Phase 1 block.

## Item-by-item results

| # | Proof | Result | Evidence |
|---|---|---|---|
| 1 | Declarative definition parses to dependency set | PASS | `->relation()`-equivalent resolves `Product -> {Category, Brand, Feature}` to 3 `rag_dependencies` rows at document-build time |
| 2 | Rows written on build; reverse lookup indexed | PASS | Same as above; index on `(dependency_type, dependency_id)` |
| 3 | Category saved -> correct document IDs, no Products loaded | PASS | Query log confirms zero queries against `products` table during resolution |
| 4 | Feature saved -> fan-out of 50k+ resolves as IDs only | PASS | 50,000 docs: **38ms** lookup. 200,000 docs: **179ms** lookup. Zero `products` queries either time. |
| 5 | Batched dispatch (~500/job), queue dedup | PASS | 50,000 docs -> **100 jobs** (not 50,000). 200,000 docs -> **400 jobs**. Full queue drain (real `queue:work` subprocess) at 200k: all 400 batches processed, 200,000 documents correctly marked stale, in 12.7s. |
| 6 | Coalescing: 10 rapid saves collapse to 1 pass | PASS | 10 back-to-back `Category::save()` calls on a category with 50k+ dependents produced exactly the 100 jobs of *one* resolve+dispatch pass, not 10x |
| 7 | Deletion cleanup: Product delete -> document + deps gone | PASS | Verified row-for-row before/after |
| 8 | belongsToMany detach removes exactly that row | PASS | Detaching Feature A from one product removed only that document's Feature-A dependency row; a second product's Feature-A dependency row was untouched |
| 9 | Dispatch after commit, never inside/surviving rollback | PASS | See finding below — required a real methodology fix to test properly |
| 10 | Mass-update bypasses events; decided | PASS | `Category::where(...)->update()` pushed **zero** jobs (events genuinely bypassed); the manual `Rag::invalidate()`-equivalent escape hatch correctly resolved and dispatched when called by hand |

**Exit criteria met:** items 1-9 clean, item 10 decided (matches
[ADR-0006](../adr/0006-transaction-queue-boundary.md)'s documented
limitation + escape-hatch design exactly).

## Findings that surprised the ADRs

### 1. No pivot model events exist in Laravel 13 (new decision needed for Phase 2)

The build plan's Phase 2 line "Observers: `created`, `updated`, `deleted`,
`restored`, plus pivot `attach`/`detach`" assumes pivot changes fire
observable model events. **They don't, in this Laravel version.**
`BelongsToMany::attach()`/`detach()` issue raw INSERT/DELETE statements
against the pivot table with no `pivotAttached`/`pivotDetached` (or any
other) Eloquent event — confirmed by reading the framework source, not just
by a failed test.

The spike worked around this with an explicit `Product::resyncRag()` call
after every `attach()`/`detach()`, which re-derives the full desired
dependency set and diffs it against what's stored (this is what makes item
8 correct — it's a full reconciliation, not a delta patch).

**This is a real gap, not just a spike shortcut.** Phase 2 needs one of:
- Require an explicit resync call after pivot changes, as a documented
  limitation analogous to the mass-update limitation already decided in
  ADR-0006 (cheapest, most honest option, consistent with ADR-0002's
  "no magic" stance).
- Ship a wrapped `attach()`/`detach()` API on `HasRag`-managed relations
  that calls resync internally (more ergonomic, more code to maintain).

Recommend deciding this explicitly — either as an amendment to ADR-0006 or
a new short ADR — **before** Phase 2's observer work starts.

### 2. The afterCommit protection only "bites" when the queue isn't the same transactional resource as the app DB

Initial attempt to prove item 9 used a single SQLite connection for both
the app and the `database` queue driver. Result: dispatching *without*
`->afterCommit()` inside a transaction that rolled back **still correctly
produced zero leaked jobs** — because on a shared connection, the queue's
own INSERT is swept into the same ambient transaction and rolls back with
everything else. That's correct SQL behavior, but it meant the test wasn't
discriminating between "with" and "without" afterCommit at all.

Fixed by giving the queue's `database` connection a **separate physical
SQLite file** from the app's default connection. With that in place, the
"without afterCommit" control case correctly leaked a job row that
survived the rollback (1 leaked job, reproduced at both 50k and 200k
scale), while the "with afterCommit" case correctly produced zero jobs on
rollback and >0 on commit.

**Implication for ADR-0006:** the ADR's decision (always dispatch
after-commit) is still correct and should not change. But its rationale is
worth sharpening: the risk it protects against is concrete and
production-relevant specifically because real queue backends (Redis, SQS,
Beanstalkd — anything other than the `database` driver sharing the app's
own connection) are **not** part of the app's database transaction at all.
Recommend a short addition to ADR-0006's Context section making this
explicit, so a future reader doesn't assume `database`-queue users are
exempt.

### 3. Coalescing mechanism, concretely

The plan/ADR-0005 describe coalescing and "queue-level deduplication" at a
policy level without picking a mechanism. The spike implemented coalescing
as an **atomic cache-based debounce lock** (`Cache::add($key, true, $ttl)`
on the `database` cache store) taken before resolving affected documents —
first caller in the window proceeds, every other caller in the window is a
no-op. This is a genuine, reusable technique, not a stub: it worked
correctly under 10 rapid repeated saves.

**Implication for ADR-0005:** no change to the decision, but the ADR
currently says "queue-level deduplication," which reads as if it means a
queue-native unique-job feature (e.g. `ShouldBeUnique`). What was actually
built and validated is a pre-resolution debounce lock, which is a
different (and in this case, better-fitting) mechanism. Recommend a small
wording clarification in ADR-0005 to describe this accurately.

### 4. Mass-insert bypasses events too (reinforces item 10, not a surprise)

Seeding 50k-200k products via `DB::table('products')->insert()` (required
for the seed step to run in seconds instead of minutes) confirmed the same
event-bypass behavior as `Category::where(...)->update()`: bulk operations
never touch Eloquent's lifecycle hooks. Already the documented, decided
answer in ADR-0006 — this is corroborating evidence, not a new finding.

## Benchmark numbers

| Documents | Seed time | Reverse-lookup time | Jobs dispatched | Full drain time |
|---|---|---|---|---|
| 50,000 | 3.0s | 38ms | 100 | 5.6s |
| 200,000 | 12.2s | 179ms | 400 | 12.7s |

Lookup time roughly scales with data size on SQLite with a plain composite
index (4x the data -> ~4.7x the lookup time) — no evidence of a missing
index or a pathological query plan. These are SQLite numbers on a spike
schema, not a substitute for the real MariaDB/pgvector benchmarks Phase 5
calls for at 10k/100k/1M scale, but they're strong evidence the fan-out
design itself doesn't have a scaling problem.

## ADR disposition

| ADR | Disposition |
|---|---|
| [ADR-0002](../adr/0002-declarative-dependency-registry.md) | **Confirmed as designed.** No revision needed. |
| [ADR-0003](../adr/0003-backend-support-matrix.md) | Not exercised by this spike (no vector backend involved). No disposition. |
| [ADR-0004](../adr/0004-identity-and-hashing-scheme.md) | **Confirmed.** Schema shape matches exactly. |
| [ADR-0005](../adr/0005-fanout-policy.md) | **Confirmed, wording clarification recommended** — "queue-level deduplication" should describe the actual debounce-lock mechanism (see Finding 3). |
| [ADR-0006](../adr/0006-transaction-queue-boundary.md) | **Confirmed, context addition recommended** — sharpen why afterCommit matters relative to non-transactional queue backends (see Finding 2). Also: Phase 2's pivot-observer approach needs a new decision (see Finding 1), since it isn't covered by this ADR or any other. |

No ADR files were edited as part of this spike — dispositions above are
recommendations for a follow-up pass, left for review.
