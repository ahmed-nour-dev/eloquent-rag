<?php

declare(strict_types=1);

use Illuminate\Database\Query\Grammars\MariaDbGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;

/**
 * Grammar::compileVectorDistanceExpression() is a pure string-compile
 * method — it only needs *a* Connection object to satisfy the base
 * Grammar constructor (never queries it), so this instantiates the real
 * MariaDbGrammar/PostgresGrammar classes directly against this package's
 * ordinary SQLite test connection, purely to compile SQL fragments and
 * assert on the resulting string. No live MariaDB/Postgres server
 * involved — this proves our code's assumptions about the exact SQL
 * Laravel emits for each backend match reality, which is exactly the kind
 * of assumption PR #61337 (referenced in the build plan) warns can shift
 * between Laravel point releases.
 */
it('compiles the MariaDB vector distance expression as vec_distance_cosine(...)', function () {
    $grammar = new MariaDbGrammar(DB::connection());

    expect($grammar->compileVectorDistanceExpression('embedding'))
        ->toBe('vec_distance_cosine(`embedding`, vec_fromtext(?))');

    expect($grammar->supportsVectorDistance())->toBeTrue();
});

it('compiles the Postgres vector distance expression using the <=> pgvector operator', function () {
    $grammar = new PostgresGrammar(DB::connection());

    expect($grammar->compileVectorDistanceExpression('embedding'))
        ->toBe('("embedding" <=> ?)');

    expect($grammar->supportsVectorDistance())->toBeTrue();
});
