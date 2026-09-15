<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Jobs\ForgetRagDocument;
use Ahmednour\EloquentRag\Jobs\SyncRagDocument;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * ADR-0010 / issue #55: both queue jobs must declare their own
 * retry/backoff/timeout policy rather than inheriting whatever the host
 * application's queue connection happens to default to, and must not be
 * ShouldBeUnique (see the ADR for why blind queue-level dedup is actively
 * unsafe here — it can drop a legitimately newer dispatch).
 */
it('gives SyncRagDocument an explicit retry, backoff, and timeout policy', function () {
    $job = new SyncRagDocument([]);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60])
        ->and($job->timeout)->toBe(120)
        ->and($job)->not->toBeInstanceOf(ShouldBeUnique::class);
});

it('gives ForgetRagDocument an explicit retry, backoff, and timeout policy', function () {
    $job = new ForgetRagDocument([]);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60])
        ->and($job->timeout)->toBe(60)
        ->and($job)->not->toBeInstanceOf(ShouldBeUnique::class);
});
