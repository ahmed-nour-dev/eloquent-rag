# Contributing

Thanks for helping improve Eloquent RAG. Bug reports, docs fixes, and pull
requests are all welcome.

## Before you start

- **Bugs and feature ideas**: open an
  [issue](https://github.com/ahmed-nour-dev/eloquent-rag/issues/new/choose)
  first for anything bigger than a small fix, so the approach can be agreed
  before you write code.
- **Security problems**: don't open a public issue — see
  [SECURITY.md](SECURITY.md).
- **Design decisions** live in [`docs/adr/`](docs/adr/). A change that
  contradicts an ADR needs a new or amended ADR in the same pull request,
  explaining why.

## Development setup

```bash
git clone https://github.com/ahmed-nour-dev/eloquent-rag.git
cd eloquent-rag
composer install
```

The package is tested with [Pest](https://pestphp.com) on top of
[Orchestra Testbench](https://github.com/orchestral/testbench). No database
server is needed for the main suite: it runs on in-memory SQLite, using the
[portable fallback](docs/backend-support.md#portable-fallback) wherever it
needs embeddings or search, and `Laravel\Ai\Embeddings::fake()` in place of
a real provider.

## Checks

Run all of these before opening a pull request — CI runs the same ones:

```bash
composer test       # vendor/bin/pest
composer format     # vendor/bin/pint (CI runs pint --test)
composer analyse    # vendor/bin/phpstan analyse
```

### Real-backend acceptance tests

`tests/Integration/` holds acceptance suites that only run against a real
MariaDB 11.7+ or PostgreSQL+pgvector server; without one they skip. To run
them locally, start a server (the images CI uses are pinned in
[`.github/workflows/tests.yml`](.github/workflows/tests.yml)) and set:

```bash
# MariaDB 11.7+
RAG_TEST_MARIADB_HOST=127.0.0.1 RAG_TEST_MARIADB_PASSWORD=root vendor/bin/pest tests/Integration/MariaDb

# PostgreSQL with pgvector (run CREATE EXTENSION vector; first)
RAG_TEST_PGSQL_HOST=127.0.0.1 RAG_TEST_PGSQL_PASSWORD=postgres vendor/bin/pest tests/Integration/Postgres
```

Changes to vector search, embedding storage, or migrations should come with
acceptance-test coverage for both backends.

## Pull requests

- Keep each pull request to one change, and reference the issue it
  addresses.
- Add or update tests for behavior changes. Add or update docs under
  `docs/` for anything user-facing.
- Add an entry under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md).
- Match the surrounding code's style; Pint enforces formatting.

## Versioning

The package follows [Semantic Versioning](https://semver.org). Which
PHP/Laravel/`laravel/ai` versions are supported, and when that changes, is
covered in [docs/support-policy.md](docs/support-policy.md).
