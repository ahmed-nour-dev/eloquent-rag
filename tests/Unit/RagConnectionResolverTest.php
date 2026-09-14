<?php

declare(strict_types=1);

use Ahmednour\EloquentRag\Support\RagConnectionResolver;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Category;
use Ahmednour\EloquentRag\Tests\Fixtures\Models\Widget;

afterEach(fn () => config(['eloquent-rag.connection' => null]));

it("falls back to the model's own connection name when no config override is set", function () {
    expect(RagConnectionResolver::resolve(Widget::class))->toBe('secondary');

    // Category has no custom $connection — getConnectionName() is null,
    // which is Laravel's own "use the default connection" signal.
    expect(RagConnectionResolver::resolve(Category::class))->toBeNull();
});

it("prefers the eloquent-rag.connection config override over the model's own connection", function () {
    config(['eloquent-rag.connection' => 'central']);

    expect(RagConnectionResolver::resolve(Widget::class))->toBe('central');
    expect(RagConnectionResolver::resolve(Category::class))->toBe('central');
});

it('accepts a model instance as well as a class string', function () {
    expect(RagConnectionResolver::resolve(new Widget))->toBe('secondary');
});
