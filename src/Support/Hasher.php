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
     * Per-chunk embedding provenance fingerprint: identifies exactly which
     * (provider, model, dimensions) produced a chunk's currently-stored
     * embedding, keyed to that chunk's own content_hash. Deliberately
     * independent from configuration()'s document-level hash, which also
     * mixes in the RagDefinition, declared relations, and chunk-layout
     * settings unrelated to the embedding call itself.
     */
    public static function embedding(string $contentHash, ?string $provider, string $model, int $dimensions): string
    {
        $payload = [
            'contentHash' => $contentHash,
            'provider' => $provider,
            'model' => $model,
            'dimensions' => $dimensions,
        ];

        return hash('sha256', self::canonicalJson($payload));
    }

    /**
     * @param  array{max_tokens: int, overlap: int, tokenizer: string}  $chunkOptions
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
            'orderedPaths' => $definition->orderedPaths(),
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
