<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Tests\Integration\MariaDbAcceptanceTestCase;
use Ahmednour\EloquentRag\Tests\Integration\PostgresAcceptanceTestCase;
use Ahmednour\EloquentRag\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Unit', __DIR__.'/Feature');

// The MariaDB/Postgres acceptance suites must bind their own TestCase here,
// not in their own tests/Integration/{MariaDb,Postgres}/Pest.php: Pest's
// BootFiles bootstrapper (vendor/pestphp/pest/src/Bootstrappers/BootFiles.php)
// only ever loads tests/Pest.php — it never recurses into subdirectories
// looking for nested Pest.php files. Those nested files were silently dead
// code: every acceptance test fell back to the plain SQLite-based TestCase
// above, so VectorBackendCapability::ensureSupported() and rag:doctor always
// saw the sqlite connection instead of the real MariaDB/Postgres one, no
// matter what the CI job's RAG_TEST_* env vars were set to.
//
// This only works because __DIR__.'/Unit' and __DIR__.'/Feature' above don't
// overlap with these two paths — Pest throws TestCaseAlreadyInUse if two
// uses()->in() calls in the same file both match the same test file.
uses(MariaDbAcceptanceTestCase::class)->in(__DIR__.'/Integration/MariaDb');
uses(PostgresAcceptanceTestCase::class)->in(__DIR__.'/Integration/Postgres');
