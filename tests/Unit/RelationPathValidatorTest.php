<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Exceptions\InvalidRelationPath;
use Ahmednour\EloquentRag\Support\RelationPathValidator;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Product;

it('accepts a path whose non-leaf segments are all real relation methods', function () {
    $product = new Product;

    RelationPathValidator::validate($product, 'category.name');
    RelationPathValidator::validate($product, 'features.name');

    expect(true)->toBeTrue();
});

it('rejects a path with no relation segment at all', function () {
    $product = new Product;

    expect(fn () => RelationPathValidator::validate($product, 'name'))
        ->toThrow(InvalidRelationPath::class, "not one of the model's own attributes");
});

it('rejects a path whose relation segment does not exist as a method', function () {
    $product = new Product;

    expect(fn () => RelationPathValidator::validate($product, 'categroy.name'))
        ->toThrow(InvalidRelationPath::class, "'categroy' is not a method on");
});

it('rejects a path whose relation segment is a real method but not an Eloquent relationship', function () {
    $product = new Product;

    expect(fn () => RelationPathValidator::validate($product, 'getKey.name'))
        ->toThrow(InvalidRelationPath::class, 'does not return an Eloquent relationship');
});
