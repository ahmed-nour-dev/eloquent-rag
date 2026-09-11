# ADR-0002: Declarative dependency registry over runtime relationship introspection

**Status:** Proposed — must be validated by the Phase 0 spike before being finalized

## Context

RAG documents rendered from an Eloquent model often include data from related
models (e.g. a `Product` document includes its `Category` name and `Brand`
name). When a related model changes, dependent documents must be found and
re-synced. There are two ways to know which documents depend on which
related models:

1. **Runtime introspection** — walk the model's declared Eloquent
   relationships and infer dependencies automatically ("magic").
2. **Declarative registration** — require the developer to explicitly state
   which relation paths a document depends on.

## Decision

Dependencies are declared explicitly, via the `RagDefinition` builder
(e.g. `->relation('category.name')`), and recorded as rows in
`rag_dependencies` at document-build time. There is no automatic or magical
discovery of dependencies from Eloquent relationship definitions.

## Consequences

- Slightly more boilerplate: every relation a document's content depends on
  must be named explicitly in the definition.
- In exchange, dependency resolution is statically knowable ahead of time.
  Reverse lookup — "which documents depend on `Category #5`?" — is a direct
  indexed query on `(dependency_type, dependency_id)`, never a runtime walk
  of relationship graphs, and never requires hydrating the dependent models.
  This is what makes bounded, ID-only fan-out possible at 50k+ documents
  (see [ADR-0005](0005-fanout-policy.md)).
- This is the single highest-risk assumption in the whole plan. It is
  proven or falsified by Phase 0 spike items 1, 3, and 4
  (`docs/spikes/0001-dependency-graph.md`). The kill criterion for the
  entire project is: if reverse lookup ends up needing runtime relationship
  walking, or fan-out can't be bounded without hydrating models, this ADR
  is wrong and the schema/API must be redesigned before Phase 1 starts.
- No automatic relationship discovery means renaming or restructuring a
  model's Eloquent relations never silently breaks or silently changes RAG
  dependency tracking — it only breaks if the declared `->relation(...)`
  path itself becomes invalid, which is a loud, obvious failure.
