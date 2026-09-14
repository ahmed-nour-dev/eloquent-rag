# Installation

## Requirements

See [backend-support.md](backend-support.md) for the full matrix. In short:
MariaDB 11.7+ on Laravel 13.29+, or PostgreSQL with the `pgvector`
extension. Plain MySQL is not supported — there is no fallback.

## Install

```bash
composer require ahmednour/eloquent-rag
```

The service provider (`Ahmednour\EloquentRag\EloquentRagServiceProvider`) is
auto-discovered — no manual registration needed in a standard Laravel app.

## Publish the config (optional)

```bash
php artisan vendor:publish --tag=eloquent-rag-config
```

This creates `config/eloquent-rag.php` with the package's defaults:

```php
return [
    'chunk' => ['max_tokens' => 400, 'overlap' => 40, 'tokenizer' => 'whitespace'],
    'embedding' => ['provider' => null, 'model' => 'text-embedding-3-small', 'dimensions' => 1536],
    'queue' => ['batch_size' => 500],
    'invalidation' => ['debounce_seconds' => 5],
];
```

You don't have to publish it to use the package — these defaults apply
automatically either way. Publish it when you want to change chunk sizing,
pin a specific embedding provider/model, or tune the fan-out batch size.

## Run the migrations

```bash
php artisan migrate
```

This creates three tables: `rag_documents`, `rag_chunks`, and
`rag_dependencies`. On a supported backend (MariaDB/PostgreSQL), a
follow-up migration also converts `rag_chunks.embedding` to a real native
vector column sized to `config('eloquent-rag.embedding.dimensions')`. On
any other connection (including SQLite, used by this package's own test
suite) that column stays a plain nullable placeholder, since there is no
vector column type to convert it to — see
[backend-support.md](backend-support.md).

## Verify your setup

```bash
php artisan rag:doctor
```

Run this **before** wiring up your first model. It checks your Laravel
version, database backend, embedding dimension configuration, queue
setup, and general health signals, and exits non-zero if anything would
otherwise fail obscurely later — see [backend-support.md](backend-support.md#rag-doctor)
for exactly what it checks and why.

## Next

- [definition-api.md](definition-api.md) — declare your first model
- [dependency-model.md](dependency-model.md) — how related models keep documents fresh
