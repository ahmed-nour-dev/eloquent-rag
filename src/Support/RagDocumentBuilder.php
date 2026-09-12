<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Support;

use Ahmednour\EloquentRag\RagDefinition;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Stringable;

/**
 * Renders a model + its declared relation data (per RagDefinition) into a
 * deterministic text document. Pure function: given the same model state
 * and definition, output is byte-identical across calls — this is Phase
 * 1's exit criterion and what makes ADR-0004's hashing scheme meaningful.
 *
 * No DB writes, no embeddings, no queues.
 */
final class RagDocumentBuilder
{
    public function render(Model $model, RagDefinition $definition): string
    {
        $lines = [];

        foreach ($definition->contentAttributes() as $attribute) {
            $lines[] = sprintf('%s: %s', $attribute, $this->canonicalize($this->resolve($model, $attribute)));
        }

        foreach ($definition->relations() as $path) {
            $lines[] = sprintf('%s: %s', $path, $this->canonicalize($this->resolve($model, $path)));
        }

        return implode("\n", $lines);
    }

    private function resolve(mixed $value, string $path): mixed
    {
        return $this->resolveSegments($value, explode('.', $path));
    }

    /**
     * Walks a dot-separated path across model attributes and relations.
     * When the current value is a collection (e.g. a belongsToMany
     * relation), the remaining path is resolved across every item, so
     * 'features.name' on a Product naturally yields a list of Feature
     * names rather than requiring special-case handling per relation type.
     *
     * @param  list<string>  $segments
     */
    private function resolveSegments(mixed $value, array $segments): mixed
    {
        if ($segments === [] || $value === null) {
            return $value;
        }

        if ($value instanceof Collection || is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): mixed => $this->resolveSegments($item, $segments))
                ->all();
        }

        $segment = array_shift($segments);
        $next = $value instanceof Model ? $value->{$segment} : data_get($value, $segment);

        return $this->resolveSegments($next, $segments);
    }

    /**
     * Converts a resolved value into a deterministic string representation.
     * Handles the concrete nondeterminism sources that would otherwise
     * break byte-identical rendering:
     *
     * - Collections from belongsToMany relations have no guaranteed
     *   retrieval order, so array/collection values are sorted before
     *   joining.
     * - Floats are formatted with a fixed precision rather than relying on
     *   PHP's `serialize_precision` ini setting or (string) casting, which
     *   is environment-dependent.
     * - Dates are normalized to UTC ISO-8601 rather than the model's
     *   ambient timezone, which can vary by app config.
     */
    private function canonicalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc()->toIso8601String();
        }

        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        if (is_array($value)) {
            $parts = array_map(fn (mixed $item): string => $this->canonicalize($item), $value);
            sort($parts, SORT_STRING);

            return implode(', ', $parts);
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
