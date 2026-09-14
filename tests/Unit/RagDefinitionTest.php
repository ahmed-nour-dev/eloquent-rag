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

it('marks declared paths as ordered without affecting content/relations lists', function () {
    $definition = Rag::make()
        ->content(['name', 'steps'])
        ->relation('category.name')
        ->relation('steps.label')
        ->ordered('steps', 'steps.label');

    expect($definition->contentAttributes())->toBe(['name', 'steps']);
    expect($definition->relations())->toBe(['category.name', 'steps.label']);
    expect($definition->orderedPaths())->toBe(['steps', 'steps.label']);
    expect($definition->isOrdered('steps'))->toBeTrue();
    expect($definition->isOrdered('steps.label'))->toBeTrue();
    expect($definition->isOrdered('category.name'))->toBeFalse();
});

it('treats no declared paths as ordered by default', function () {
    $definition = Rag::make()->content(['name'])->relation('category.name');

    expect($definition->orderedPaths())->toBe([]);
    expect($definition->isOrdered('name'))->toBeFalse();
    expect($definition->isOrdered('category.name'))->toBeFalse();
});
