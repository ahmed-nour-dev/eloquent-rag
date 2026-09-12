# ADR-0007: Pivot change detection for `belongsToMany` dependencies

**Status:** Accepted

## Context

The build plan's Phase 2 line lists observers as "`created`, `updated`,
`deleted`, `restored`, plus pivot `attach`/`detach`," implicitly assuming
pivot table changes fire observable Eloquent events analogous to model
lifecycle events.

The Phase 0 spike (`docs/spikes/0001-dependency-graph.md`, Finding 1)
confirmed — by reading the framework source, not just by a failed test —
that this assumption is false: `BelongsToMany::attach()` and `detach()`
issue raw `INSERT`/`DELETE` statements against the pivot table with no
`pivotAttached`, `pivotDetached`, or any other Eloquent event to hook into.

This matters because `Product`'s `belongsToMany Feature` relationship
(and any other declared `belongsToMany` dependency, per
[ADR-0002](0002-declarative-dependency-registry.md)) needs its
`rag_dependencies` rows kept in sync with the pivot table whenever a
Feature is attached to or detached from a Product — and there is no
built-in signal to trigger that.

The spike validated a workaround — an explicit `resyncRag()` call that
re-derives the full desired dependency set for a model and diffs it
against what's stored — which correctly handles attach/detach/detach-all
as a full reconciliation rather than a delta patch (this is what made
spike item 8 pass). But *how* and *when* that call happens in the real
package is an open product decision, not yet made.

## Options

1. **Documented limitation + manual call**, consistent with the
   mass-update limitation already decided in
   [ADR-0006](0006-transaction-queue-boundary.md) and with ADR-0002's
   "no magic" stance: developers must call `$product->resyncRag()` (or
   equivalent) after any `attach()`/`detach()`/`sync()` on a declared
   `belongsToMany` dependency. Cheapest to build and maintain; consistent
   with the package's existing philosophy of explicit over automatic;
   costs the developer an easy-to-forget step with no automatic detection.
2. **Wrapped relation API**: `HasRag`-managed `belongsToMany` relations
   get package-provided `attach()`/`detach()`/`sync()` wrappers (or a
   macro) that call the resync internally, so the common path "just
   works" without a manual step. More ergonomic; more surface area to
   maintain and document; still needs a documented limitation for anyone
   who bypasses the wrapper and touches the pivot table directly (e.g. via
   `DB::table('feature_product')`).

## Decision

**Option 1: documented limitation + manual call.** Developers must call
`$model->resyncRag()` (exposed by the `HasRag` trait, Phase 2) after any
`attach()`, `detach()`, or `sync()` on a declared `belongsToMany`
dependency. There is no wrapped/intercepting relation API.

This follows the same shape as the mass-update limitation already decided
in [ADR-0006](0006-transaction-queue-boundary.md): an explicit, documented
escape hatch rather than an attempt to intercept a write path Eloquent
doesn't naturally expose a hook for. It's also the option consistent with
[ADR-0002](0002-declarative-dependency-registry.md)'s "no magic" stance —
a wrapped `attach()`/`detach()` API would be exactly the kind of implicit,
convention-guessing behavior that ADR-0002 already rejected for
relationship discovery. A wrapper's ergonomic win is real but narrow (it
still can't cover raw pivot-table writes via `DB::table(...)`, so a
documented limitation is required either way) and not worth the extra
API surface and maintenance cost.

`resyncRag()` re-derives the model's full desired dependency set from its
`RagDefinition` and diffs it against what's stored in `rag_dependencies`
— a full reconciliation, not a delta patch — exactly as validated by the
Phase 0 spike (`docs/spikes/0001-dependency-graph.md`, item 8).

## Consequences

- `resyncRag()` must exist as a first-class, documented public method on
  `HasRag`-using models from Phase 2 onward — not an internal-only helper
  — the same way `Rag::invalidate()` is first-class per ADR-0006.
- This limitation must be documented alongside the mass-update limitation:
  in `docs/principles.md`, the main docs (Phase 5), and anywhere the
  `belongsToMany` dependency declaration is documented, since it's an easy
  trap for a developer who assumes `->relation('features.name')` alone
  makes attach/detach automatically tracked.
- `rag:doctor` (Phase 4) should detect pivot/dependency drift — a pivot
  row with no corresponding `rag_dependencies` row, or vice versa — as a
  health check. This is independent of the decision above and is cheap
  insurance regardless: manual calls will sometimes be forgotten, and
  `rag:doctor`'s job is to catch exactly that class of mistake before it's
  an obscure search-quality bug.
