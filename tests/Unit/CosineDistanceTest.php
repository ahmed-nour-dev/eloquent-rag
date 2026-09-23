<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Support\CosineDistance;

/**
 * Pure math, no database — matches Laravel's native vector-distance
 * convention (0.0 = identical, 2.0 = diametrically opposite) so RagSearch's
 * ADR-0011 fallback path can treat this interchangeably with the native
 * selectVectorDistance()/whereVectorDistanceLessThan() output.
 */
it('returns 0.0 for identical vectors', function () {
    expect(CosineDistance::between([1.0, 0.0, 0.0], [1.0, 0.0, 0.0]))->toBe(0.0);
});

it('returns 1.0 for orthogonal vectors', function () {
    expect(CosineDistance::between([1.0, 0.0], [0.0, 1.0]))->toBe(1.0);
});

it('returns 2.0 for diametrically opposite vectors', function () {
    expect(CosineDistance::between([1.0, 0.0], [-1.0, 0.0]))->toBe(2.0);
});

it('computes a known non-trivial cosine distance', function () {
    // cos(theta) between [1, 1] and [1, 0] is 1/sqrt(2) ~= 0.70710678
    $distance = CosineDistance::between([1.0, 1.0], [1.0, 0.0]);

    expect($distance)->toBeGreaterThan(0.29289)->toBeLessThan(0.29290);
});

it('is unaffected by vector magnitude, only direction', function () {
    $unit = CosineDistance::between([1.0, 0.0], [1.0, 0.0]);
    $scaled = CosineDistance::between([1.0, 0.0], [50.0, 0.0]);

    expect($scaled)->toBe($unit);
});
