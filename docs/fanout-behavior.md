# Fan-out behavior and its limits

## The scale problem

Renaming a `Category` shared by 50,000 `Product` documents must not
dispatch 50,000 individual jobs, and ten rapid edits to that same category
while someone is typing must not trigger ten independent passes over all
50,000 documents. Both of these are handled automatically — see
[ADR-0005](adr/0005-fanout-policy.md) for the design and
[benchmarks.md](benchmarks.md) for the numbers this was validated against.

## Batching

Once the [reverse lookup](dependency-model.md#the-reverse-lookup) resolves
which documents are affected, their `(model_type, model_id)` pairs are
split into batches of `config('eloquent-rag.queue.batch_size')` (default
500) and each batch is dispatched as one `SyncRagDocument` job — never one
job per document. Override the batch size for a single CLI run with
`php artisan rag:sync --chunk=1000`.

## Coalescing

Rapid repeated saves to the same dependency (ten quick edits to one
`Category`) collapse into a **single** resolve-and-dispatch pass, via an
atomic cache lock (`Cache::add`) taken before resolution runs — the first
save in a `config('eloquent-rag.invalidation.debounce_seconds')` window
(default 5 seconds) triggers the pass, every other save in that window is
a no-op. This means a burst of edits is handled once, but it also means:
if you need to *guarantee* the very latest state is reflected immediately
after a save (rather than "within the debounce window"), don't rely on the
automatic path for that save — call `$model->rag()->sync()` directly, or
reduce `debounce_seconds` for your workload.

## Real limits, stated plainly

- **Batch size is a memory/throughput trade-off you control**, not a
  correctness guarantee. A larger batch means fewer jobs but a slower,
  heavier queue worker per job.
- **The debounce window is real coalescing, not a guarantee of freshness.**
  A save that lands inside another save's debounce window doesn't get its
  own resolve pass — it's covered by the one already in flight, which
  re-renders current database state when it runs, but that run's timing is
  not tied to the specific save that triggered it.
- **Every batch fully re-renders and re-hashes each targeted document**
  (via the same `sync()` used everywhere else), so an unrelated dependency
  change that doesn't actually alter a document's rendered content is
  cheap — the content-hash short-circuit still applies per document, it's
  only the *resolution and dispatch* that's batched, not a promise that
  every dispatched document actually gets rewritten.

## Retry, timeout, and uniqueness policy

`SyncRagDocument` and `ForgetRagDocument` both declare an explicit
`$tries = 3` with a `$backoff` of `[10, 60]` seconds, rather than inheriting
whatever your queue connection's own defaults happen to be.
`SyncRagDocument`'s `$timeout` is 120 seconds (sized for its largest batch,
`config('eloquent-rag.queue.batch_size')`); `ForgetRagDocument`'s is 60
seconds, since a delete-by-key batch is lighter per pair. These retries
exist for infrastructure hiccups (a dropped DB connection, a deadlock)
between or before pairs in a batch — a single pair's own `sync()` failure
is already caught and recorded on that document's row instead (issue #54),
never retried.

With [automatic embedding](definition-api.md#automatic-embedding) on,
`SyncRagDocument` hands the documents it actually rewrote to a separate
`EmbedRagDocuments` job (batches of `embedding.auto_batch_size`, default
50) rather than calling the embedding provider itself. It has the same
`$tries = 3` / `$backoff = [10, 60]` and per-document failure isolation,
with a longer `$timeout` of 300 seconds, since each document costs a
provider round-trip.

None of these jobs is `ShouldBeUnique`. This is a deliberate decision, not an
oversight: a model's own queued sync and a dependency fan-out that also
covers it can land on the queue back-to-back, and a queue-level dedup key
would risk silently dropping the newer of the two rather than letting both
run — `sync()`'s content/configuration-hash short-circuit already makes a
duplicate run cheap, not incorrect, so there's nothing to gain from
deduplicating at the queue and a real way to lose correctness by doing so.
Repeated dependency-triggered fan-out is already coalesced correctly, and
earlier — before a batch is even built — by the debounce lock described
above. See [ADR-0010](adr/0010-queue-retry-policy.md) for the full
reasoning.

## The mass-update and pivot limitations

**These are the two most important gotchas in the whole package — read
this section even if you skip everything else.**

`Category::where(...)->update()` and `DB::table(...)->insert()` **bypass
Eloquent's model events entirely**. Nothing in this package observes them,
by design — see [ADR-0006](adr/0006-transaction-queue-boundary.md). If a
mass update changes something declared as a dependency (a bulk category
rename script, for instance), you must trigger invalidation yourself:

```php
use Ahmednour\EloquentRag\Rag;

Category::where('parent_id', 5)->update(['name' => 'New Name']);
Rag::invalidate(Category::class, $affectedCategoryIds); // int, string, or array
```

Or from the CLI: `php artisan rag:sync --dependency="App\Models\Category:5"`.

Similarly, `$product->features()->attach($featureId)` /
`->detach($featureId)` **fire no Eloquent events at all** — this is a real
gap in what Eloquent exposes for pivot tables, not a design choice this
package made. Call `$product->resyncRag()` afterward:

```php
$product->features()->attach($feature->id);
$product->resyncRag(); // re-derives and reconciles rag_dependencies for $product
```

Or use the one-call helpers `HasRag` provides, so there's no second line
to forget:

```php
$product->attachRag('features', $feature->id);             // attach() + resyncRag()
$product->detachRag('features', $feature->id);             // detach() + resyncRag()
$product->syncRagRelation('features', [$a->id, $b->id]);   // sync()   + resyncRag()
```

They take the same arguments as `attach()`/`detach()`/`sync()` (plus the
relation name first), return what those return, and throw
`InvalidArgumentException` if the relation isn't a `belongsToMany`.

Forgetting any of this doesn't error — it silently leaves stale dependency
rows or a stale rendered document, and no event is left behind for
`rag:doctor` to find. What *is* left behind is the drift itself, and
`rag:verify` looks for exactly that:

```bash
php artisan rag:verify                        # every indexed model type
php artisan rag:verify "App\Models\Product"   # one type, also finds rows with no document
php artisan rag:verify --sample=500           # spot-check 500 random documents per type
php artisan rag:verify --fix                  # re-sync whatever drifted
```

It re-renders each document's model and compares the result against the
stored `content_hash`, `configuration_hash`, and `rag_dependencies` rows,
reporting any mismatch (plus documents whose model row is gone — clean
those up with `rag:prune`). It can't tell you *which* write path caused a
drift, only that one happened. It exits non-zero while unfixed drift
remains, so it works as a scheduled job or CI check. `--fix` re-syncs
structurally; generate embeddings for the re-synced documents with
`rag:sync`, or let [automatic embedding](definition-api.md#automatic-embedding)
do it.

Still treat the invalidation calls above as part of the write path —
`rag:verify` is how you catch the one you missed, not a replacement for
making them.
