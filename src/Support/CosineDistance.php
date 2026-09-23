<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag\Support;

/**
 * Pure PHP cosine distance, used by RagSearch's ADR-0011 fallback ranking
 * path in place of Laravel's native selectVectorDistance()/
 * whereVectorDistanceLessThan(). Matches that native convention exactly so
 * RagSearch can treat both paths' distance values interchangeably: 0.0 is
 * identical, 2.0 is diametrically opposite, and `1 - $minSimilarity` is the
 * equivalent distance ceiling for a given minimum cosine similarity.
 */
final class CosineDistance
{
    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function between(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value ** 2;
            $normB += $b[$i] ** 2;
        }

        return 1 - ($dot / (sqrt($normA) * sqrt($normB)));
    }
}
