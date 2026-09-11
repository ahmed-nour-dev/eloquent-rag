# Eloquent RAG

The Eloquent synchronization layer for Laravel AI's RAG stack.

> This package does not implement vector search. It integrates Eloquent
> models with Laravel's AI and vector capabilities, providing model
> lifecycle synchronization, dependency tracking, invalidation, chunk
> management, and rebuild tooling.

## Status

Early development, following a phased build plan. No release yet — see
[Support matrix](#support-matrix) and [Roadmap](#roadmap) below before
depending on this in production.

## Requirements

| Target | Supported | Requirement |
|---|---|---|
| MariaDB 11.7+ | ✅ | Laravel **13.27+** (vector query builder API) |
| PostgreSQL + pgvector | ✅ | Laravel 13.x |
| Plain MySQL 8.x | ❌ | No native vector backend — not supported |

See [ADR-0003](docs/adr/0003-backend-support-matrix.md) for the rationale.

## Installation

```bash
composer require ahmednour/eloquent-rag
```

*(Not yet published to Packagist — installation instructions will be
updated at release.)*

## Design principles

This package's scope, non-goals, and the architecture decisions behind it
are documented, not implicit:

- [docs/principles.md](docs/principles.md) — core principle + explicit non-goals
- [docs/adr/](docs/adr/) — Architecture Decision Records

## Roadmap

Development follows a phased plan: dependency-graph spike → document/chunk
model → Eloquent lifecycle → Laravel AI integration → CLI & operability →
release. See `.orca/drops/eloquent-rag-build-plan.md` for the full plan.

## License

MIT. See [LICENSE](LICENSE).
