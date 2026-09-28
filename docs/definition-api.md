# Definition API

## Declaring a model

Add the `HasRag` trait and implement `toRagDefinition()`:

```php
use Ahmednour\EloquentRag\Concerns\HasRag;
use Ahmednour\EloquentRag\Rag;
use Ahmednour\EloquentRag\RagDefinition;

class Product extends Model
{
    use HasRag;

    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function features(): BelongsToMany { return $this->belongsToMany(Feature::class); }

    public function toRagDefinition(): RagDefinition
    {
        return Rag::make()
            ->content(['name', 'sku', 'price'])
            ->relation('category.name')
            ->relation('brand.name')
            ->relation('features.name');
    }
}
```

`->content([...])` lists the model's own attributes to render into the
document, in the order given. `->relation('path.to.attribute')` declares
both a piece of rendered content **and** a dependency: the document is
recorded as depending on the related model(s) at that path, so a change to
`Category #5`'s name (say) is what triggers this product's document to be
re-synced later — see [dependency-model.md](dependency-model.md).

There is deliberately no automatic discovery of what to render or depend
on. If it isn't declared, it isn't tracked — see
[ADR-0002](adr/0002-declarative-dependency-registry.md).

## Preserving order

Canonicalization sorts array-valued content attributes and relation paths
before rendering, so that things like a `belongsToMany` collection (which
has no guaranteed retrieval order) don't cause spurious `content_hash`
changes across syncs. Not every array is an unordered collection, though —
a numbered list of steps has a meaning encoded in its order:

```php
public function steps(): HasMany { return $this->hasMany(Step::class)->orderBy('position'); }

public function toRagDefinition(): RagDefinition
{
    return Rag::make()
        ->content(['name'])
        ->relation('steps.label')
        ->ordered('steps.label');
}
```

`->ordered(...$paths)` marks already-declared content attributes or
relation paths (by the same string passed to `content()`/`relation()`) as
order-preserving: canonicalization renders their values in the order
they're resolved in, rather than sorting them. It has no effect on paths
that resolve to a scalar.

Every segment of a `->relation()` path except the last must name a real
Eloquent relationship method (`category` and `brand` above, for instance)
— `sync()` validates this and throws `InvalidRelationPath` immediately on
a mistyped or since-renamed segment, rather than silently recording an
empty dependency set for it. The last segment is the attribute being
rendered and isn't validated, since attributes can come from accessors or
casts with nothing static to check.

## Why `toRagDefinition()`, not `rag()`

An early design sketch named the definition method `rag()`. In the actual API,
`rag()` is the *runtime* entry point (below) — it needs to stay a distinct
name from the per-model definition method so the trait can provide both a
declaration point and a stateful action object without a naming collision.

## Runtime actions

```php
$product->rag()->sync();     // structural sync: render, hash, chunk, register dependencies
$product->rag()->embed();    // generate real embeddings for any un-embedded chunks
$product->resyncRag();       // shortcut for rag()->sync() — see the pivot-attach/detach note below
```

**`sync()`** renders the document, computes the two hashes described in
[ADR-0004](adr/0004-identity-and-hashing-scheme.md), and — only if
something actually changed — reconciles the `rag_documents` /
`rag_chunks` / `rag_dependencies` rows. It never generates an embedding
and never requires a vector-capable backend; it's a plain structural
operation you can run against SQLite in a test.

`sync()` returns `true` when it actually rewrote the document and `false`
when it short-circuited on unchanged content.

**`embed()`** requires a supported backend, or the opt-in
[portable fallback](backend-support.md#portable-fallback) (it throws
`UnsupportedVectorBackend` immediately otherwise), and generates real
embeddings via `laravel/ai` for any chunk that doesn't have one yet. It is
**not** called by `sync()` itself — see
[backend-support.md](backend-support.md) for why that separation matters.
Use `php artisan rag:sync` to run both together, or turn on
[automatic embedding](#automatic-embedding).

## Automatic lifecycle

Once a model uses `HasRag`, its `created`, `updated`, and `restored`
events automatically queue a structural `sync()` (dispatched only after
the enclosing transaction commits — [ADR-0006](adr/0006-transaction-queue-boundary.md)),
and `deleted` removes its document (chunks and dependencies cascade).
Nothing needs to be called manually for normal create/update/delete flows.

> **First-run gotcha:** by default a save only runs the *structural*
> `sync()`. The document exists, but it has no embeddings yet
> (`status = 'pending'`), so `searchRag()` right after saving returns
> nothing until embeddings are generated. Either run
> `php artisan rag:sync`, or enable automatic embedding below.
> `php artisan rag:doctor` warns when documents are waiting for
> embeddings.

## Automatic embedding

```php
// config/eloquent-rag.php — or RAG_AUTO_EMBED=true in .env
'embedding' => [
    // ...
    'auto' => true,
    'auto_batch_size' => 50,
],
```

With `embedding.auto` on, whenever the queued lifecycle sync (or a
dependency fan-out) actually rewrites a document, an `EmbedRagDocuments`
job is queued for it, in batches of `auto_batch_size`. Documents whose
content didn't change are skipped. Embedding failures are recorded on the
document row (`status = 'failed'`, `last_error`), where `rag:doctor` and
`rag:status` report them, and never fail the rest of the batch.

It's off by default because every content-changing save then calls your
embedding provider — mind cost and rate limits for write-heavy models.
Direct `$model->rag()->sync()` calls are unaffected: only the queued
lifecycle and fan-out path chains embedding.

## Lifecycle events

Hook into sync and embedding without polling `rag:status` — for logging,
Horizon tags, metrics, or a Pulse card:

| Event | When |
|---|---|
| `Ahmednour\EloquentRag\Events\RagDocumentSynced` | `sync()` actually rewrote a document (not on an unchanged short-circuit). Has `model`, `document`, `forced` (`true` for `rag:rebuild`). |
| `Ahmednour\EloquentRag\Events\RagDocumentEmbedded` | `embed()` wrote at least one embedding and the document is now fully embedded, i.e. searchable. Has `model`, `document`, `embeddedChunks`. |
| `Ahmednour\EloquentRag\Events\RagSyncFailed` | `sync()` threw. Has `model`, `exception`. |
| `Ahmednour\EloquentRag\Events\RagEmbeddingFailed` | `embed()` threw (unsupported backend, provider error, invalid response). Has `model`, `exception`. |

They fire from every path — the queued lifecycle jobs, fan-out, the CLI
commands, and direct calls. The two success events implement
`ShouldDispatchAfterCommit`, so inside a transaction they're held until it
commits. The failure events fire just before the exception propagates;
the queued jobs and CLI commands then record the failure on the document
row as before.

```php
use Ahmednour\EloquentRag\Events\RagEmbeddingFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (RagEmbeddingFailed $event) {
    Log::warning('RAG embedding failed', [
        'model' => $event->model::class,
        'id' => $event->model->getKey(),
        'error' => $event->exception->getMessage(),
    ]);
});
```

## What's *not* automatic

- **`belongsToMany` attach/detach** — call `$model->resyncRag()` afterward.
  Eloquent fires no events for pivot table changes at all, so there is
  nothing for the package to observe — see
  [ADR-0007](adr/0007-pivot-change-detection.md) and the callout in
  [fanout-behavior.md](fanout-behavior.md#the-mass-update-and-pivot-limitations).
- **Mass updates and bulk inserts** — `Category::where(...)->update()` and
  `DB::table(...)->insert()` bypass Eloquent events entirely. Use
  `Rag::invalidate()` — see [fanout-behavior.md](fanout-behavior.md#the-mass-update-and-pivot-limitations).
- **Embedding** — an explicit step (`embed()`, or `rag:sync`/`rag:rebuild`)
  unless you enable [automatic embedding](#automatic-embedding).

## Search

```php
Product::searchRag('a bluetooth speaker', limit: 10);   // static, scoped to Product
Rag::search(Product::class, 'a bluetooth speaker');      // equivalent, class-agnostic form
```

Both return a hydrated `Illuminate\Database\Eloquent\Collection` of the
owner model (`Product`, not `RagDocument`/`RagChunk`), ordered by vector
distance. `Product::rag()->search(...)` — the form an early design sketch
used — isn't possible in PHP once `rag()` already exists as a real
instance method (a class can't have one method be both instance and
static), so `searchRag()` is the static entry point instead.

`limit` must be at least 1 — `0` or a negative value throws an
`InvalidArgumentException`. A `limit` above
`config('eloquent-rag.search.max_limit')` (default `1000`) is silently
clamped down to it rather than rejected, to guard against an accidentally
expensive vector query.

### Filtering by relevance

A nearest-neighbor match is not necessarily a *relevant* one — for a query
unrelated to anything indexed, the nearest `limit` chunks are still
returned by default, however distant they are. Pass `minSimilarity` to
apply a floor instead:

```php
Product::searchRag('a bluetooth speaker', limit: 10, minSimilarity: 0.70);
```

`minSimilarity` is a cosine similarity in `[0.0, 1.0]`, where `1.0` is
identical. Chunks scoring below the threshold are excluded entirely,
rather than merely ranked last — a query with no sufficiently close match
can return fewer than `limit` results, or none. Omitting it (the default)
preserves the original no-floor behavior.

### Scores and matching chunks

`searchRag()` returns bare models. For RAG proper — grounding an LLM
answer, showing citations, or thresholding in your own code — ask for the
score and the matching chunk too:

```php
$results = Product::searchRagWithScores('a bluetooth speaker', limit: 5);
// or: Rag::searchWithScores(Product::class, 'a bluetooth speaker', limit: 5);

foreach ($results as $result) {
    $result->model;       // the hydrated Product
    $result->score;       // cosine similarity, 1.0 = identical (same scale as minSimilarity)
    $result->distance;    // cosine distance, i.e. 1 - score
    $result->chunkIndex;  // which of the document's chunks matched best
    $result->chunk();     // that chunk's text, or null (see below)
}
```

The result is an `Illuminate\Support\Collection` of
`Ahmednour\EloquentRag\RagSearchResult`, in the same order, with the same
`limit`/`minSimilarity` semantics, as `searchRag()`. Each document is still
scored by its single closest chunk; `chunkIndex` tells you which one.

Chunk text is not stored in the database (only its hash is), so `chunk()`
re-renders and re-chunks the model the same way `sync()` does, on first
call, and memoizes the result. It returns `null` instead of guessing when
the re-derived text's hash no longer matches the chunk that was actually
embedded — the model changed after its last `sync()`/`embed()`, so the
stored vector describes text that no longer exists. Calling it lazy-loads
the relations your definition renders, so only call it for results you
use.

### Scoping the query

```php
use Ahmednour\EloquentRag\RagSearch;

(new RagSearch(Product::class))
    ->scope(fn ($q) => $q->where('rag_documents.status', 'synced'))
    ->search('a bluetooth speaker');
```

`scope()` (available on the `RagSearch` instance, not the static
`searchRag()` helper) hands the callback the query builder that produces
chunk-level rows, mid-construction: the `rag_chunks`/`rag_documents` join
and the fixed `model_type`/`whereNotNull('embedding')` predicates are
already on it, and it still has a `select()`/`selectVectorDistance()` (and,
when `minSimilarity` is set, a `whereVectorDistanceLessThan()`) to come
before it's wrapped in `fromSub()` and grouped/ordered by `MIN(distance)`
per `model_id`.

This makes `scope()` **filter-only**. Safe: `where`/`whereHas`-style
predicates against `rag_chunks`/`rag_documents` columns. Unsupported —
these don't throw, they silently produce wrong results:

- `orWhere` at the top level, which combines with the fixed
  `model_type`/`whereNotNull` predicates by operator precedence and can
  defeat them. Wrap it instead:
  `->where(fn ($q) => $q->where(...)->orWhere(...))`.
- `select()`/`addSelect()`, `groupBy()`, `orderBy()` — ranking depends on
  exactly `model_id` + `distance` being selected and on its own
  grouping/ordering, applied after this callback runs.
- `limit()`/`offset()` — the result limit is applied once, on the outer
  aggregated query.
