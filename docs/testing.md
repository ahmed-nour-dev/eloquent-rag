# Testing your models

Your application's test suite shouldn't need MariaDB 11.7+ or pgvector, or
an embedding API key, just to test code that uses `HasRag`. `Rag::fake()`
swaps this package's side effects for a recording test double, in the
spirit of Laravel's own `Queue::fake()` / `Event::fake()`.

## `Rag::fake()`

```php
use Ahmednour\EloquentRag\Rag;

it('indexes a product with its category', function () {
    $rag = Rag::fake();

    $product = Product::factory()->for(Category::factory()->state(['name' => 'Audio']))->create();

    $rag->assertSynced($product);
    $rag->assertSynced(Product::class, fn (Product $synced, string $rendered) =>
        str_contains($rendered, 'category.name: Audio'));
});
```

While the fake is active:

| Real behavior | Under `Rag::fake()` |
|---|---|
| `created`/`updated`/`restored` queue a `SyncRagDocument` job | The sync is recorded **immediately**, whatever queue driver your tests use |
| `sync()` writes `rag_documents`/`rag_chunks`/`rag_dependencies` | Nothing is written. The definition's relation paths are still validated and the document is still rendered, so a broken `toRagDefinition()` still throws |
| `embed()` calls your embedding provider | Recorded; no provider call |
| `deleted` / `forget()` removes the document | Recorded |
| `Rag::invalidate()` and the automatic "a model was saved" invalidation | Recorded, with no dependency lookup, so every save shows up |
| `searchRag()` / `Rag::search()` / `searchRagWithScores()` | Recorded; returns what you stubbed (empty by default). `limit`/`minSimilarity` are still validated |

The fake is stored in the container, so it only lasts for the current
test. Nothing needs resetting.

## Asserting on syncs

```php
$rag->assertSynced($product);                  // this model instance (class + key)
$rag->assertSynced(Product::class);            // any Product
$rag->assertSynced($product, fn (Product $model, string $rendered) => /* ... */ true);
$rag->assertSyncedTimes($product, 1);
$rag->assertNotSynced(Product::class);
$rag->assertNothingSynced();

$rag->renderedFor($product);                   // the rendered text of its latest sync
```

To check a definition's output without going through a save, render it
directly. This runs the same relation-path validation and rendering as
`sync()`:

```php
expect(Rag::fake()->render($product))->toBe(<<<'TXT'
name: Speaker
sku: SPK-1
price: 49.99
category.name: Audio
brand.name: Acme
features.name: Bluetooth, Waterproof
TXT);
```

## Embedding, removal, and invalidation

```php
$rag->assertEmbedded($product);
$rag->assertNotEmbedded(Product::class);

$rag->assertForgotten($product);               // or: assertForgotten(Product::class, $id)

$rag->assertInvalidated(Category::class, $category->id);
```

## Search

Stub what search returns per model class, as a list of models or a closure
that receives the query and limit:

```php
$rag = Rag::fake()->searchReturns(Product::class, [$speaker, $headphones]);

$rag->searchReturns(Product::class, fn (string $query, int $limit) =>
    $query === 'speakers' ? [$speaker] : []);

$this->getJson('/search?q=speakers')->assertJsonPath('data.0.id', $speaker->id);

$rag->assertSearched(Product::class, 'speakers');
$rag->assertSearched(Product::class, fn (string $query, int $limit, ?float $minSimilarity) => $limit === 5);
$rag->assertNothingSearched();
```

`searchRagWithScores()` wraps stubbed models as `RagSearchResult`s with a
`score` of `1.0` and no chunk.

## Without the fake

If you want the real pipeline in tests, your suite's database decides what
works:

- **Structural sync works on any database**, including SQLite. The
  package's migrations run automatically, so saving a `HasRag` model
  writes real `rag_documents`/`rag_chunks`/`rag_dependencies` rows you
  can assert on.
- **`embed()` and search need a vector backend.** On SQLite or plain MySQL,
  enable the [portable fallback](backend-support.md#portable-fallback)
  (`RAG_PORTABLE_FALLBACK=true` in `phpunit.xml`) and fake the provider
  with `Laravel\Ai\Embeddings::fake()`, and the whole sync → embed →
  search cycle runs for real. Pass `Embeddings::fake()` a closure that
  maps each input to a vector if you need predictable ranking.
