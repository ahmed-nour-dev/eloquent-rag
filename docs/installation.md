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

## Custom database connections

If an indexed model declares its own connection:

```php
class Product extends Model
{
    protected $connection = 'tenant';
}
```

its `rag_documents`/`rag_chunks`/`rag_dependencies` rows are stored and
queried on that same `'tenant'` connection automatically — no
configuration needed. This corrects a prior bug rather than adding an
opt-in feature: earlier versions silently wrote and read this data on the
default connection regardless of a model's own connection.

Resolution is by connection *name*, not a snapshotted connection instance,
so this works transparently with multi-tenancy packages (e.g.
stancl/tenancy) that keep the connection name fixed while swapping what
physical database it points to per request/job.

Run migrations on each connection that needs these tables the normal
Laravel way:

```bash
php artisan migrate --database=tenant
```

(or your tenancy package's own per-connection migration command).

### Centralizing on one connection instead

To force **all** RAG data onto a single connection regardless of what
connection each indexed model itself uses, set `connection` in
`config/eloquent-rag.php`:

```php
'connection' => 'central',
```

#### Search hydration when the two connections differ

`->searchRag()`/`RagSearch::search()` always ranks against the resolved RAG
connection (`central` above, or the model's own connection when no override
is set) but hydrates the final results via the searched model's own
Eloquent connection — i.e. `Product::query()`, using whatever connection
`Product` itself declares (or the app default, if none). This is
intentional, not a bug: a shared, centralized RAG index over per-connection
(e.g. per-tenant) owner data is a supported configuration, and the owner
rows it ranks are only ever readable on their own connection in the first
place. Concretely, with the config above and a `Product` pinned to
`protected $connection = 'tenant';`, a search ranks chunks on `central` and
then reads the matching `Product` rows from `tenant` — never from
`central`. This holds regardless of whether the two connections happen to
coincide.

### Dependency invalidation

A dependency (a related model declared via `->relation()` in a
`RagDefinition`) is assumed to live on the same connection as the
documents that reference it — e.g. a tenant's `Product` depending on that
same tenant's `Category`. Cross-connection dependency graphs are not
supported.

### `rag:status`, `rag:prune`, `rag:doctor`, `rag:sync`, `rag:rebuild`

These commands operate on one connection per invocation. When no model
class is given (bulk mode), pass `--connection=` to target a connection
other than the default:

```bash
php artisan rag:status --connection=tenant
php artisan rag:doctor --connection=tenant
php artisan rag:prune --connection=tenant
php artisan rag:sync --connection=tenant
```

For a multi-connection setup, run the command once per connection.
`rag:sync`/`rag:rebuild` with an explicit model argument, and
`rag:forget`/`rag:dependencies` (which always require one), ignore
`--connection` — the given model's own connection is used instead.

## Next

- [definition-api.md](definition-api.md) — declare your first model
- [dependency-model.md](dependency-model.md) — how related models keep documents fresh
