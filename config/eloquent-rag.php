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
    */
    'chunk' => [
        'max_tokens' => 400,
        'overlap' => 40,
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
];
