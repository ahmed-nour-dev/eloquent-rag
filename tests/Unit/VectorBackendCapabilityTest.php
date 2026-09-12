<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Exceptions\UnsupportedVectorBackend;
use Ahmednour\EloquentRag\VectorBackendCapability;

/**
 * meetsMariaDbFloor() and extensionRowIndicatesInstalled() are pure
 * functions — no database involved, table-driven against literal input.
 */
it('compares a clean MariaDB version string against the ADR-0003 floor (11.7.0)', function (string $version, bool $expected) {
    expect(VectorBackendCapability::meetsMariaDbFloor($version))->toBe($expected);
})->with([
    ['11.7.0', true],
    ['11.7.2', true],
    ['12.0.0', true],
    ['11.6.99', false],
    ['10.6.23', false],
    ['10.11.9', false],
]);

it('treats a pg_extension row as installed only when a row is actually returned', function () {
    expect(VectorBackendCapability::extensionRowIndicatesInstalled(null))->toBeFalse();
    expect(VectorBackendCapability::extensionRowIndicatesInstalled((object) ['extversion' => '0.7.0']))->toBeTrue();
});

/**
 * This is a REAL test, not mocked: this package's own test suite runs on
 * SQLite, which is a genuine, real example of an ADR-0003-unsupported
 * backend. No fake connection or fake grammar involved.
 */
it('rejects the real SQLite test connection as an unsupported vector backend', function () {
    expect(fn () => VectorBackendCapability::ensureSupported())
        ->toThrow(UnsupportedVectorBackend::class);
});
