<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Support;

use Ahmednour\EloquentRag\RagDefinition;
use JsonException;

/**
 * Implements ADR-0004's two-hash scheme: content_hash over rendered source
 * data, configuration_hash over everything else that determines the output
 * (definition, chunk settings, embedding model/dimensions).
 */
final class Hasher
{
    public static function content(string $renderedDocument): string
    {
        return hash('sha256', $renderedDocument);
    }

    /**
     * @param  array{max_tokens: int, overlap: int}  $chunkOptions
     */
    public static function configuration(
        RagDefinition $definition,
        array $chunkOptions,
        ?string $embeddingProvider,
        string $embeddingModel,
        int $embeddingDimensions,
    ): string {
        $payload = [
            'content' => $definition->contentAttributes(),
            'relations' => $definition->relations(),
            'chunk' => $chunkOptions,
            'embedding' => [
                'provider' => $embeddingProvider,
                'model' => $embeddingModel,
                'dimensions' => $embeddingDimensions,
            ],
        ];

        return hash('sha256', self::canonicalJson($payload));
    }

    /**
     * Canonical JSON: associative arrays are key-sorted so map ordering
     * never affects the hash; sequential (list) arrays keep their given
     * order, since declaration order of content()/relation() calls is
     * itself part of the deterministic definition, not incidental.
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws JsonException
     */
    private static function canonicalJson(array $data): string
    {
        $json = json_encode(
            self::sortRecursively($data),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return $json;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function sortRecursively(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortRecursively($value);
            }
        }

        return $data;
    }
}
