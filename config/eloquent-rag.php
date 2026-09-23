<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Chunking defaults
    |--------------------------------------------------------------------------
    |
    | Fed into Chunker and into ADR-0004's configuration_hash. Changing these
    | values invalidates every document via the configuration hash, not the
    | content hash — no separate migration step is needed.
    |
    | `tokenizer` selects the Tokenizer Chunker uses to split text into
    | token-sized pieces: 'whitespace' (the default, zero dependencies,
    | approximates tokens by word count) or the fully-qualified class name
    | of your own class implementing Ahmednour\EloquentRag\Support\Tokenizer
    | for real model-aware sizing (e.g. wrapping a tiktoken binding). See
    | ADR-0009 (docs/adr/0009-tokenizer-abstraction.md) and
    | docs/tokenization.md.
    */
    'chunk' => [
        'max_tokens' => 400,
        'overlap' => 40,
        'tokenizer' => 'whitespace',
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding identity
    |--------------------------------------------------------------------------
    |
    | Fed into ADR-0004's configuration_hash (so switching models/dimensions
    | invalidates every document automatically) and, from Phase 3 onward,
    | into the real laravel/ai Embeddings::for(...)->generate() call.
    | `provider` is null by default, meaning "use laravel/ai's own
    | config('ai.default_for_embeddings')" — set it explicitly to pin a
    | specific provider regardless of the app's general AI default.
    */
    'embedding' => [
        'provider' => null,
        'model' => 'text-embedding-3-small',
        'dimensions' => 1536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fan-out batching
    |--------------------------------------------------------------------------
    |
    | Bounded batch size per ADR-0005 — a single dependency change fans out
    | to affected documents in chunks of this size, never one job per
    | document. Tunable per deployment (also exposed via rag:sync --chunk
    | in Phase 4).
    */
    'queue' => [
        'batch_size' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Invalidation coalescing
    |--------------------------------------------------------------------------
    |
    | Debounce window (seconds) for the atomic cache lock described in
    | ADR-0005: repeated saves against the same dependency within this
    | window collapse into a single invalidation pass.
    */
    'invalidation' => [
        'debounce_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | `max_limit` caps the `limit` argument accepted by
    | `Product::searchRag()` / `Rag::search()` — a caller-supplied limit
    | above this is silently clamped down to it, protecting against
    | accidentally expensive vector queries. A limit below 1 is rejected
    | outright (InvalidArgumentException) rather than clamped, since there's
    | no sane number of results to substitute for it.
    */
    'search' => [
        'max_limit' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | Which database connection rag_documents/rag_chunks/rag_dependencies
    | live on. Null (the default) mirrors each indexed model's own
    | connection automatically — if a model declares
    | `protected $connection = 'tenant';`, its RAG data is stored on the
    | 'tenant' connection too. Set this to a specific connection name to
    | force ALL RAG data onto one connection regardless of indexed models'
    | own connections. See docs/installation.md#custom-database-connections.
    */
    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Portable fallback backend (opt-in, dev/small-scale — see ADR-0011)
    |--------------------------------------------------------------------------
    |
    | Off by default: nothing about this package's behavior changes unless
    | you explicitly opt in. When `enabled` is true, embed()/search() no
    | longer refuse to run on a connection ADR-0003 doesn't natively
    | support (SQLite, or plain/unconfigured MySQL) — RagSearch instead
    | ranks chunks by computing cosine similarity in PHP against every
    | candidate chunk's already-stored embedding. Storage is unaffected;
    | AsVector already JSON-encodes the embedding column on these drivers.
    |
    | This does NOT extend to a genuinely misconfigured *supported*
    | backend (MariaDB below the 11.7 floor, Postgres missing pgvector) —
    | those still hard-fail, since the fix there is to upgrade/enable the
    | extension, not to silently degrade.
    |
    | This path has no index and no query-plan optimizer: every matching
    | chunk for the searched model type is pulled into PHP and compared
    | one at a time. `max_candidate_chunks` is a hard ceiling on how many
    | chunks a single search() call will scan before refusing to run
    | (rather than silently getting slower as data grows) — raise it only
    | if you understand the cost, and never rely on this path at
    | production scale. See docs/backend-support.md#fallback-backend.
    */
    'fallback' => [
        'enabled' => false,
        'max_candidate_chunks' => 5000,
    ],
];
