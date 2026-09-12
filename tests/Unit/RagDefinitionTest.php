<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Rag;

it('builds a fluent definition preserving declared order', function () {
    $definition = Rag::make()
        ->content(['name', 'sku'])
        ->relation('category.name')
        ->relation('brand.name');

    expect($definition->contentAttributes())->toBe(['name', 'sku']);
    expect($definition->relations())->toBe(['category.name', 'brand.name']);
});

it('starts with no content or relations declared', function () {
    $definition = Rag::make();

    expect($definition->contentAttributes())->toBe([]);
    expect($definition->relations())->toBe([]);
});
