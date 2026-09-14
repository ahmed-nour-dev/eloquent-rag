# Dependency model

## The problem

A document's rendered content often includes data from related models —
`Product`'s document includes its `Category`'s name, for instance. When
that `Category` is renamed, every `Product` document that rendered its old
name needs to be re-synced. The question is how the package knows *which*
documents depend on *which* related models, without loading everything
into memory to find out.

## Declarative, not discovered

Every `->relation('category.name')` call in a model's `toRagDefinition()`
does two things at once: it renders that value into the document, and it
registers a dependency. There is no attempt to infer dependencies by
inspecting a model's Eloquent relationship definitions — see
[ADR-0002](adr/0002-declarative-dependency-registry.md) for why: it keeps
the reverse lookup below a plain indexed query instead of a runtime graph
walk, and it means a model's relations can be refactored freely without
silently changing what this package tracks.

## What gets recorded

On every `sync()`, the package resolves each declared relation path down
to the **related model instance(s)** it denotes (not the leaf attribute —
`category.name` depends on the `Category` row, not on its `name` column
specifically) and fully reconciles the `rag_dependencies` table for that
document: existing rows for the document are deleted and the current,
correct set is re-inserted. This is a full reconciliation on every sync,
not an incremental patch — deliberately, since it's what makes attach/detach
handling correct (see below) without extra bookkeeping.

```
rag_dependencies
  document_id, dependency_type, dependency_id
  index (dependency_type, dependency_id)   <- the reverse-lookup hot path
  index (document_id)
```

A `belongsToMany` relation (`->relation('features.name')`) produces one
dependency row per related model in the collection — a product with three
attached features gets three `Feature` dependency rows.

Before any of this runs, `sync()` validates that every segment of every
declared path except the last is a real Eloquent relationship method on
the model it's called against — a mistyped or since-renamed segment
throws `InvalidRelationPath` immediately instead of silently resolving to
an empty dependency set. This is what makes ADR-0002's claim true that an
invalid declared path is "a loud, obvious failure."

## The reverse lookup

When any model is saved, the package checks whether `(get_class($model),
$model->getKey())` appears as a `dependency_type`/`dependency_id` pair in
`rag_dependencies`. This is a single indexed query — it never loads or
touches the models that might depend on it until it already knows their
IDs. Measured at 38ms against 50,000 simulated dependent documents and
179ms against 200,000 (SQLite; see [benchmarks.md](benchmarks.md)) — the
lookup cost doesn't come from the number of things that *could* depend on
a change, only from the index itself.

What happens once matching documents are found is covered in
[fanout-behavior.md](fanout-behavior.md).

## The pivot-attach/detach gap

`BelongsToMany::attach()`/`detach()` issue raw SQL against the pivot table
with no Eloquent event to hook into at all — this isn't a limitation the
package chose, it's a real gap in what Eloquent exposes. See
[ADR-0007](adr/0007-pivot-change-detection.md) for the full reasoning and
[definition-api.md](definition-api.md#whats-not-automatic) for the
required manual call.
