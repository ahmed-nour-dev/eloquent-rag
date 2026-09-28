# Version support policy

What this package supports, how that's tested, and when support widens.

## Current support

| Dependency | Supported | CI coverage |
|---|---|---|
| PHP | 8.3, 8.4, 8.5 | Every version, on both database backends |
| Laravel (`illuminate/*`) | 13.29+ | The 13.29.0 floor and the latest 13.x |
| `laravel/ai` | 0.11.2+ and 1.x (`^0.11.2 \|\| ^1.0`) | 0.11.2 on the floor leg, the latest 1.x on the latest leg, and the unreleased `1.x-dev` branch on an allowed-to-fail leg |
| MariaDB | 11.7+ | 11.7.2 |
| PostgreSQL + pgvector | pgvector installed | PostgreSQL 16 + pgvector 0.8.6 |

See [backend-support.md](backend-support.md) for why the Laravel floor is
13.29 rather than 13.0, and for the development-only
[portable fallback](backend-support.md#portable-fallback) on SQLite and
MySQL.

## How the CI matrix maps to this

[`.github/workflows/tests.yml`](../.github/workflows/tests.yml) runs on
every push to `main`, on every pull request, and weekly:

- **floor** legs pin the oldest supported combination (Laravel 13.29.0,
  `laravel/ai` 0.11.2).
- **latest** legs run `composer update`, i.e. the newest versions every
  constraint allows.
- **`laravel/ai` 1.x-dev** runs the unit and feature suites against
  `laravel/ai`'s unreleased development branch. It's allowed to fail:
  it's there to show upstream breakage *before* a release reaches users,
  not to block this package's own pull requests. The weekly run makes sure
  it fires even in a week with no commits.

## When constraints widen

Dependency constraints are widened only after the new version has passed
this package's full CI matrix, never ahead of time:

- **`laravel/ai`.** Its 1.0 release made breaking changes to parts of its
  API this package doesn't use (agents, conversations, streaming, response
  constructors). The part it does use —
  `Embeddings::for(...)->dimensions(...)->generate(...)` and the response's
  `embeddings` — is unchanged, so both 0.11 and 1.x are allowed. A future
  `laravel/ai` major is added only once the `1.x-dev` (then next-branch)
  leg and a release run are green, and the next-branch leg moves to the new
  development branch when one opens.
- **Laravel.** New 13.x point releases are covered automatically by the
  latest legs. The floor moves only when this package starts to need a
  newer framework feature, and a new Laravel major is added the same way as
  a `laravel/ai` major.
- **PHP.** New PHP versions are added to the matrix when GitHub's
  `setup-php` action supports them, and support is claimed once they pass.

Dropping support for an old version (raising a floor) is a breaking change
and happens only in a new minor version while this package is pre-1.0, and
is recorded in [CHANGELOG.md](../CHANGELOG.md).

## Reporting a breakage

If a new release of Laravel or `laravel/ai` breaks this package before CI
catches it, please [open an issue](https://github.com/ahmed-nour-dev/eloquent-rag/issues)
with the versions from `composer show laravel/ai laravel/framework`.
