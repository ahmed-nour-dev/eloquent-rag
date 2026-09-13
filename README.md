# Eloquent RAG

The Eloquent synchronization layer for Laravel AI's RAG stack.

> This package does not implement vector search. It integrates Eloquent
> models with Laravel's AI and vector capabilities, providing model
> lifecycle synchronization, dependency tracking, invalidation, chunk
> management, and rebuild tooling.

## Status

All five phases of the build plan are complete: dependency-graph spike,
document/chunk model, Eloquent lifecycle, Laravel AI integration, CLI &
operability, and release readiness. The `ahmednour/eloquent-rag` name is
confirmed available on both Packagist and GitHub, but **the package is not
yet published** — there's no tagged release and no CI run against a real
MariaDB/PostgreSQL server yet (this was built and tested against SQLite in
a sandbox with no real vector-capable database available; see
[docs/benchmarks.md](docs/benchmarks.md) and the acceptance tests under
`tests/Integration/` for exactly what that does and doesn't prove). Don't
depend on this in production until a real release exists.

## Requirements

| Target | Supported | Requirement |
|---|---|---|
| MariaDB 11.7+ | ✅ | Laravel **13.29+** (vector query builder API) |
| PostgreSQL + pgvector | ✅ | Laravel 13.x |
| Plain MySQL 8.x | ❌ | No native vector backend — not supported |

See [docs/backend-support.md](docs/backend-support.md) for the full
picture (including why the check is stricter than Laravel's own) or
[ADR-0003](docs/adr/0003-backend-support-matrix.md) for the original
decision record.

## Installation

```bash
composer require ahmednour/eloquent-rag
```

*(Not yet published to Packagist — the name is confirmed available, but
there's no tagged release yet. See [docs/installation.md](docs/installation.md)
for the full setup once one exists.)*

## Documentation

- [docs/installation.md](docs/installation.md)
- [docs/definition-api.md](docs/definition-api.md) — `HasRag`, `toRagDefinition()`, sync/embed/search
- [docs/dependency-model.md](docs/dependency-model.md) — how documents declare and track dependencies
- [docs/fanout-behavior.md](docs/fanout-behavior.md) — batching, coalescing, and the two limitations you need to know about
- [docs/backend-support.md](docs/backend-support.md) — the support matrix and what `rag:doctor` checks
- [docs/rebuild-and-migration.md](docs/rebuild-and-migration.md) — choosing between `rag:sync`/`rag:rebuild`/`rag:prune`/`rag:forget`
- [docs/benchmarks.md](docs/benchmarks.md) — fan-out at 10k/100k/1M documents, published honestly
- [docs/principles.md](docs/principles.md) — core principle + explicit non-goals
- [docs/adr/](docs/adr/) — Architecture Decision Records

## Roadmap

Development followed a phased plan: dependency-graph spike → document/chunk
model → Eloquent lifecycle → Laravel AI integration → CLI & operability →
release. All five phases are complete. See
`.orca/drops/eloquent-rag-build-plan.md` for the full plan and
`docs/spikes/0001-dependency-graph.md` for the spike that validated the
riskiest part of the design before any of it was built.

## License

MIT. See [LICENSE](LICENSE).
