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

The build plan describes this as a `rag()` method. In the actual API,
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

**`embed()`** requires a supported backend (it calls
`VectorBackendCapability::ensureSupported()` first, throwing
`UnsupportedVectorBackend` immediately if not) and generates real
embeddings via `laravel/ai` for any chunk that doesn't have one yet. It is
**not** called automatically by `sync()` or by the automatic lifecycle
hooks below — see [backend-support.md](backend-support.md) for why that
separation matters, and use `php artisan rag:sync` to run both together
for real operational work.

## Automatic lifecycle

Once a model uses `HasRag`, its `created`, `updated`, and `restored`
events automatically queue a structural `sync()` (dispatched only after
the enclosing transaction commits — [ADR-0006](adr/0006-transaction-queue-boundary.md)),
and `deleted` removes its document (chunks and dependencies cascade).
Nothing needs to be called manually for normal create/update/delete flows.

## What's *not* automatic

- **`belongsToMany` attach/detach** — call `$model->resyncRag()` afterward.
  Eloquent fires no events for pivot table changes at all, so there is
  nothing for the package to observe — see
  [ADR-0007](adr/0007-pivot-change-detection.md) and the callout in
  [fanout-behavior.md](fanout-behavior.md#the-mass-update-and-pivot-limitations).
- **Mass updates and bulk inserts** — `Category::where(...)->update()` and
  `DB::table(...)->insert()` bypass Eloquent events entirely. Use
  `Rag::invalidate()` — see [fanout-behavior.md](fanout-behavior.md#the-mass-update-and-pivot-limitations).
- **Embedding** — always an explicit step (`embed()`, or `rag:sync`/`rag:rebuild`),
  never automatic.

## Search

```php
Product::searchRag('a bluetooth speaker', limit: 10);   // static, scoped to Product
Rag::search(Product::class, 'a bluetooth speaker');      // equivalent, class-agnostic form
```

Both return a hydrated `Illuminate\Database\Eloquent\Collection` of the
owner model (`Product`, not `RagDocument`/`RagChunk`), ordered by vector
distance. `Product::rag()->search(...)` — the literal form named in the
build plan — isn't possible in PHP once `rag()` already exists as a real
instance method (a class can't have one method be both instance and
static), so `searchRag()` is the static entry point instead.

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
