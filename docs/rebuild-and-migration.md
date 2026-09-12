# Rebuild, sync, and cleanup

Four commands cover normal operation, forced rebuilds, and cleanup. All
seven `rag:*` commands are listed in [installation.md](installation.md);
this page is about choosing the right one.

## `rag:sync` — normal operation

```bash
php artisan rag:sync                                    # every known model type
php artisan rag:sync "App\Models\Product"                # every Product
php artisan rag:sync "App\Models\Product" --id=123        # one specific Product
php artisan rag:sync --dependency="App\Models\Category:5" # invalidate a dependency directly
php artisan rag:sync "App\Models\Product" --chunk=1000     # override the fan-out batch size
```

For each targeted model, this calls `sync()` then `embed()` — the normal
path to get a model fully indexed and searchable, not just structurally
synced. One failing model doesn't abort the run: failures are caught per
model, recorded as `status = 'failed'` with the exception message in
`last_error` on that document, and the run continues to the next model.
The final summary reports how many succeeded and how many failed;
`rag:doctor` surfaces failed documents afterward.

With no `model` argument and no `--dependency`, it operates over every
`model_type` **already present** in `rag_documents` — a model type with
zero synced documents yet isn't discovered this way. Target it explicitly
at least once first.

## `rag:rebuild` — forced, versioned replacement

```bash
php artisan rag:rebuild "App\Models\Product"
php artisan rag:rebuild "App\Models\Product" --id=123
```

Use this, not `rag:sync`, when:

- You changed `config('eloquent-rag.embedding.model')` or `.dimensions`
  (this already invalidates every document via `configuration_hash`
  automatically, but `rag:rebuild` is how you actually push the re-sync
  through rather than waiting for the next unrelated save).
- You changed chunking settings (`max_tokens`/`overlap`) and want the new
  boundaries applied immediately.
- You fixed a bug in a model's `toRagDefinition()` rendering and need
  already-synced documents to pick up the corrected output even though
  their *source data* hasn't changed (a normal `sync()` would short-circuit
  on the unchanged content hash, since from its perspective nothing about
  the model changed — only the code that renders it did).

Unlike `rag:sync`, `rag:rebuild` bypasses the staleness short-circuit
entirely: it always re-renders, re-chunks, and re-embeds, and it bumps the
document's `version` column each time. The document/chunk/dependency
writes happen inside a single database transaction — a failure partway
through never leaves a document pointing at half-replaced chunks or
dependencies, which is what makes this "safe replacement," not
delete-and-pray, per the build plan's own description of this command.

## `rag:prune` — cleanup after events were bypassed

```bash
php artisan rag:prune
```

If a model was deleted through a path that bypasses Eloquent events
entirely (`DB::table('products')->where('id', 123)->delete()`), its
`rag_documents` row is never cleaned up automatically — the same
mass-operation gap described in
[fanout-behavior.md](fanout-behavior.md#the-mass-update-and-pivot-limitations).
`rag:prune` finds every `rag_documents` row whose underlying model no
longer exists and deletes it (chunks/dependencies cascade). Safe to run
on a schedule.

## `rag:forget` / `rag:dependencies` — direct inspection and cleanup

```bash
php artisan rag:forget "App\Models\Product" --id=123        # delete one document directly
php artisan rag:dependencies "App\Models\Product" --id=123  # list what it depends on
```

Both work by querying `rag_documents` directly rather than hydrating the
model — `rag:forget` in particular is meant to work even when the
underlying model row is already gone, as a manual escape hatch alongside
`rag:prune`.
