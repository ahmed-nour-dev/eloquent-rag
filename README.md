# Eloquent RAG

[![Latest version on Packagist](https://img.shields.io/packagist/v/ahmednour/eloquent-rag.svg?include_prereleases)](https://packagist.org/packages/ahmednour/eloquent-rag)
[![Tests](https://github.com/ahmed-nour-dev/eloquent-rag/actions/workflows/tests.yml/badge.svg)](https://github.com/ahmed-nour-dev/eloquent-rag/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![PHP 8.3+](https://img.shields.io/badge/php-8.3%2B-777bb4.svg)
![Laravel 13.29+](https://img.shields.io/badge/laravel-13.29%2B-ff2d20.svg)

Keep your Eloquent models' RAG index correct, automatically — including
when the data a document depends on lives in *other* models.

Eloquent RAG is the synchronization layer between your Eloquent models and
Laravel AI's embeddings and native vector search: model lifecycle sync,
dependency tracking, invalidation, chunking, and rebuild tooling. It does
not implement vector search itself; it keeps what Laravel's vector search
reads up to date.

## Example

```php
use Ahmednour\EloquentRag\Concerns\HasRag;
use Ahmednour\EloquentRag\Rag;
use Ahmednour\EloquentRag\RagDefinition;

class Product extends Model
{
    use HasRag;

    public function toRagDefinition(): RagDefinition
    {
        return Rag::make()
            ->content(['name', 'description', 'price'])  // this model's own attributes
            ->relation('category.name')                  // data from related models,
            ->relation('features.name');                 // tracked as dependencies
    }
}

// Saving a product re-syncs its document on the queue. Renaming a category
// re-syncs every product that depends on it, in bounded batches.
Product::create([...]);
$category->update(['name' => 'Audio']);

// Hydrated Product models, most relevant first:
$products = Product::searchRag('wireless speaker for the shower', limit: 5);

// Or with scores and the matching text, for grounding an LLM answer:
foreach (Product::searchRagWithScores('wireless speaker', limit: 5) as $result) {
    $result->model;   // Product
    $result->score;   // cosine similarity, 0.0–1.0
    $result->chunk(); // the matching chunk of the rendered document (null if stale)
}
```

Embeddings are generated with `php artisan rag:sync`, or automatically after
each save with `RAG_AUTO_EMBED=true` — see
[automatic embedding](docs/definition-api.md#automatic-embedding).

## Why not just call Laravel AI's vector search directly?

Embedding a row and searching it is the easy part. The hard part is knowing
*when an embedding has gone stale*. A product's document includes its
category's name, so renaming that category changes 50,000 products'
documents, but none of those product rows changed, so nothing re-embeds
them. Eloquent RAG records, per document, which related models it was
rendered from. When one of those changes, it finds every affected document
through an indexed reverse lookup and re-syncs them in bounded, coalesced
queue batches (ten quick edits to one category produce one pass, not ten),
skipping documents whose rendered content didn't actually change. See
[fan-out behavior](docs/fanout-behavior.md) and the
[benchmarks](docs/benchmarks.md) (1,000,000 dependent documents resolved and
dispatched in under 3 seconds on SQLite).

## Requirements

| Target | Supported | Requirement |
|---|---|---|
| MariaDB 11.7+ | ✅ | Laravel **13.29+** (vector query builder API) |
| PostgreSQL + pgvector | ✅ | Laravel 13.x |
| Plain MySQL / SQLite | ⚠️ dev only | No native vector search; opt-in [portable fallback](docs/backend-support.md#portable-fallback) for development and small data |

PHP 8.3+, and `laravel/ai` 0.11.2+ or 1.x. See
[docs/backend-support.md](docs/backend-support.md) and
[docs/support-policy.md](docs/support-policy.md).

## Installation

```bash
composer require ahmednour/eloquent-rag
php artisan migrate
php artisan rag:doctor
```

`rag:doctor` checks your Laravel version, database backend, embedding
dimensions, and queue setup before anything gets indexed. See
[docs/installation.md](docs/installation.md) for the full setup.

## Status

Beta (`v0.1.0-beta.1` on
[Packagist](https://packagist.org/packages/ahmednour/eloquent-rag)). The
correctness-critical paths are covered by unit and feature tests plus
acceptance suites that run in CI against real MariaDB 11.7 and
PostgreSQL+pgvector servers across the supported PHP/Laravel matrix, but
the package has no production usage history yet, so treat it accordingly.
Changes are tracked in [CHANGELOG.md](CHANGELOG.md).

## Documentation

- [Installation](docs/installation.md)
- [Definition API](docs/definition-api.md): `HasRag`, `toRagDefinition()`, sync, embed, search, events
- [Dependency model](docs/dependency-model.md): how documents declare and track dependencies
- [Fan-out behavior](docs/fanout-behavior.md): batching, coalescing, and the two gotchas you need to know about
- [Backend support](docs/backend-support.md): the support matrix, the portable fallback, and `rag:doctor`
- [Tokenization](docs/tokenization.md): chunk sizing, the `tiktoken` driver, custom tokenizers
- [Rebuild and migration](docs/rebuild-and-migration.md): `rag:sync`, `rag:rebuild`, `rag:verify`, `rag:prune`, `rag:forget`
- [Testing your models](docs/testing.md): `Rag::fake()`
- [Benchmarks](docs/benchmarks.md): fan-out at 10k/100k/1M documents
- [Support policy](docs/support-policy.md): supported PHP/Laravel/`laravel/ai` versions
- [Principles](docs/principles.md): the core principle and explicit non-goals
- [Architecture Decision Records](docs/adr/)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues
privately, as described in [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
