# Fan-out benchmarks

## Methodology

Extends the dependency-graph spike's validated approach
([docs/spikes/0001-dependency-graph.md](spikes/0001-dependency-graph.md))
to 10k / 100k / 1M dependent documents, against this package's real,
permanent schema (`benchmarks/FanoutBenchmarkTest.php`, not part of the
regular test suite — run it explicitly with
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

## Running it yourself

| Variable | Meaning |
|---|---|
| `RAG_BENCH_BACKEND` | `sqlite` (default, in-memory), `mariadb`, or `pgsql` |
| `RAG_BENCH_SCALES` | Comma-separated document counts, default `10000,100000,1000000` |
| `RAG_BENCH_REPORT` | Optional file path; each scale appends a Markdown table row to it |
| `RAG_TEST_MARIADB_*` / `RAG_TEST_PGSQL_*` | Connection details — the same variables the acceptance suites under `tests/Integration/` read |

```bash
# SQLite baseline
vendor/bin/pest benchmarks/FanoutBenchmarkTest.php

# A real MariaDB 11.7+ server
RAG_BENCH_BACKEND=mariadb RAG_TEST_MARIADB_HOST=127.0.0.1 RAG_TEST_MARIADB_PASSWORD=root \
    vendor/bin/pest benchmarks/FanoutBenchmarkTest.php

# A real PostgreSQL server with pgvector (run CREATE EXTENSION vector first)
RAG_BENCH_BACKEND=pgsql RAG_TEST_PGSQL_HOST=127.0.0.1 RAG_TEST_PGSQL_PASSWORD=postgres \
    vendor/bin/pest benchmarks/FanoutBenchmarkTest.php
```

The [`benchmarks` workflow](../.github/workflows/benchmarks.yml) does
exactly this against the same MariaDB 11.7.2 and pgvector 0.8.6 / PG16
service containers the test workflow uses. It runs monthly and on manual
dispatch (never on every PR — seeding 1,000,000 rows into a real server
takes minutes), and writes a results table into the run's summary page.

Publishing stays manual on purpose: numbers are copied from a real run's
output into this file by hand, so a stale figure here can't silently drift
from what was measured without someone visibly re-running the benchmark
and editing the doc.

## Results

### SQLite baseline

SQLite `:memory:`, single run on a development machine, 2026-09-13:

| Documents | Seed time | Reverse-lookup time | Batches dispatched | Resolve + dispatch time |
|---|---|---|---|---|
| 10,000 | 0.18s | 8.8ms | 20 | 0.02s |
| 100,000 | 1.71s | 113.8ms | 200 | 0.24s |
| 1,000,000 | 17.60s | 1,319.4ms | 2,000 | 2.90s |

All three scales completed the full run — 1,000,000 was not scaled down.
Every `Bus::assertDispatchedTimes(SyncRagDocument::class, ...)` assertion
passed exactly (20 / 200 / 2,000 batches, never one job per document),
confirming bounded, batched fan-out held at every scale tested, matching
the dependency-graph spike's finding at a smaller scale (50k/200k).

Lookup time scales roughly linearly with data size (100x the data, ~150x
the lookup time — slightly worse than strictly linear, consistent with a
plain B-tree index lookup plus join over a larger result set, not evidence
of a missing index or a pathological query plan). Resolve+dispatch time
is dominated by materializing the affected-pairs collection before
chunking it — the same cost the real `DependencyInvalidator` pays in
production.

### MariaDB 11.7 and PostgreSQL + pgvector

Measured by the [`benchmarks` workflow](../.github/workflows/benchmarks.yml)
on a GitHub-hosted `ubuntu-latest` runner, with the database in a service
container on the same host (so there is a real client/server round-trip,
but over loopback).

*Not yet published.* The workflow and backend-selectable benchmark landed
together; the first run's numbers go here, copied from that run's summary
table. Until then, treat the SQLite baseline above as the only published
figure.

## What this is, and isn't, evidence of

**This is strong evidence the fan-out *design* doesn't have an inherent
scaling problem**: reverse lookup and batch dispatch both stayed
sub-3-second even at 1,000,000 simulated dependent documents, and neither
step hydrates a single `Product` model along the way.

**It is not a production load test.** Even the real-backend numbers come
from a single CI runner with no replication, no connection-pooling
contention, and no concurrent write load. A production MariaDB or
PostgreSQL server under real traffic will have different absolute numbers
— likely slower for the reverse lookup (network + real disk I/O) but
potentially more consistent under concurrent load than a single SQLite
file. Re-run the benchmark against your own infrastructure (see
[Running it yourself](#running-it-yourself)) if absolute numbers matter to
you.
