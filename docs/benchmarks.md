# Fan-out benchmarks

## Methodology

Extends the Phase 0 spike's validated approach
([docs/spikes/0001-dependency-graph.md](spikes/0001-dependency-graph.md))
to the scale the build plan calls for, against this package's real,
permanent schema (`benchmarks/FanoutBenchmarkTest.php`, not a permanent
part of the test suite — run it explicitly with
`vendor/bin/pest benchmarks/FanoutBenchmarkTest.php`).

At each scale, N `rag_documents` rows are bulk-inserted (bypassing model
events entirely, matching how a real seeded dataset would arrive), and all
N are given a `rag_dependencies` row pointing at the *same single*
`Category`, simulating the worst case this design exists to bound: one
category rename fanning out to every one of N dependent documents.
Measured:

- **Reverse lookup** — the same query shape as
  `DependencyInvalidator::resolveAffectedPairs()`: an indexed join from
  `rag_dependencies` to `rag_documents`, returning `(model_type,
  model_id)` pairs only, never hydrating a `Product` model.
- **Resolve + dispatch** — a full, real call to
  `DependencyInvalidator::invalidate()`, with `Bus::fake()` capturing how
  many `SyncRagDocument` batches actually get dispatched (asserted to
  exactly match `⌈N / batch_size⌉`, batch size 500).

## Results

Measured on this sandbox's SQLite connection (`:memory:`), single run,
2026-09-13:

| Documents | Seed time | Reverse-lookup time | Batches dispatched | Resolve + dispatch time |
|---|---|---|---|---|
| 10,000 | 0.18s | 8.8ms | 20 | 0.02s |
| 100,000 | 1.71s | 113.8ms | 200 | 0.24s |
| 1,000,000 | 17.60s | 1,319.4ms | 2,000 | 2.90s |

All three scales completed the full run — 1,000,000 was not scaled down.
Every `Bus::assertDispatchedTimes(SyncRagDocument::class, ...)` assertion
passed exactly (20 / 200 / 2,000 batches, never one job per document),
confirming bounded, batched fan-out held at every scale tested, matching
the Phase 0 spike's finding at a smaller scale (50k/200k).

Lookup time scales roughly linearly with data size (100x the data, ~150x
the lookup time — slightly worse than strictly linear, consistent with a
plain B-tree index lookup plus join over a larger result set, not evidence
of a missing index or a pathological query plan). Resolve+dispatch time
is dominated by materializing the affected-pairs collection before
chunking it — the same cost the real `DependencyInvalidator` pays in
production.

## What this is, and isn't, evidence of

**This is strong evidence the fan-out *design* doesn't have an inherent
scaling problem**: reverse lookup and batch dispatch both stayed
sub-3-second even at 1,000,000 simulated dependent documents, and neither
step hydrates a single `Product` model along the way.

**This is not a substitute for real MariaDB 11.7+/PostgreSQL+pgvector
benchmarks.** These numbers are SQLite, on this sandbox's hardware, with
no network round-trip to a database server, no replication, no connection
pooling contention, and no concurrent write load. A production MariaDB or
PostgreSQL server under real traffic will have different absolute numbers
— likely slower for the reverse lookup (network + real disk I/O) but
potentially more consistent under concurrent load than a single SQLite
file. Re-running `benchmarks/FanoutBenchmarkTest.php` against the real
backends provisioned by `.github/workflows/tests.yml` (pointed at
`RAG_TEST_MARIADB_*`/`RAG_TEST_PGSQL_*`) is the natural next step once
that CI has actually run — see the honest gap statement in the Phase 5
summary for what remains unverified in this sandbox.
