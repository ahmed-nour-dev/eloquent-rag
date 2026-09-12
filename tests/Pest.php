<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

// Real-backend acceptance suites (Phase 5) bind their own TestCase via their
// own nested Pest.php files (tests/Integration/MariaDb/Pest.php,
// tests/Integration/Postgres/Pest.php) rather than here — Pest does not
// allow a directory already covered by uses()->in(__DIR__) above to be
// rebound to a different TestCase from this same file ("TestCaseAlreadyInUse").
// See each nested Pest.php and its corresponding *AcceptanceTestCase's
// docblock for why they must never share a base with the main SQLite-based
// suite above.
