<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Services;

use Proxbet\Statistic\HtMetricsCalculator;

/**
 * Service for calculating weighted form metrics for Algorithm 1 v2.
 * 
 * Integrates with HtMetricsCalculator to extract weighted form data
 * from SGI statistics.
 */
final class WeightedFormService
{
    private HtMetricsCalculator $calculator;

    public function __construct(HtMetricsCalculator $calculator)
    {
        $this->calculator = $calculator;
    }

    /**
     * Extract weighted form data from SGI statistics.
     *
     * @param array<string,mixed> $sgi SGI JSON data
     * @param string $home Home team name
     * @param string $away Away team name
     * @return array{
     *     home: array{attack: float, defense: float}|null,
     *     away: array{attack: float, defense: float}|null,
     *     score: float|null,
     *     has_data: bool
     * }
     */
    public function getWeightedForm(array $sgi, string $home, string $away): array
    {
        $result = $this->calculator->calculate($sgi, $home, $away);
        $v2Data = $result['debug']['algorithm1_v2']['form'] ?? null;

        if ($v2Data === null) {
            return [
                'home' => null,
                'away' => null,
                'score' => null,
                'has_data' => false,
            ];
        }

        $homeData = $v2Data['home'] ?? null;
        $awayData = $v2Data['away'] ?? null;
        $score = $v2Data['weighted_score'] ?? null;

        $hasData = $homeData !== null 
            && $awayData !== null 
            && isset($homeData['attack'], $homeData['defense'])
            && isset($awayData['attack'], $awayData['defense']);

        return [
            'home' => $hasData ? [
                'attack' => (float) $homeData['attack'],
                'defense' => (float) $homeData['defense'],
            ] : null,
            'away' => $hasData ? [
                'attack' => (float) $awayData['attack'],
                'defense' => (float) $awayData['defense'],
            ] : null,
            'score' => $score !== null ? (float) $score : null,
            'has_data' => $hasData,
        ];
    }

    /**
     * Calculate weighted form score from raw metrics.
     * 
     * Formula: (home_attack * 0.6 + away_defense * 0.4 + away_attack * 0.6 + home_defense * 0.4) / 2
     *
     * @param array{attack: float, defense: float} $homeMetrics
     * @param array{attack: float, defense: float} $awayMetrics
     * @return float
     */
    public function calculateScore(array $homeMetrics, array $awayMetrics): float
    {
        $score = (
            $homeMetrics['attack'] * 0.6 +
            $awayMetrics['defense'] * 0.4 +
            $awayMetrics['attack'] * 0.6 +
            $homeMetrics['defense'] * 0.4
        ) / 2.0;

        return $score;
    }

    /**
     * Normalize weighted form score to 0-1 range.
     * 
     * Assumes typical HT goals range is 0-2 per team.
     *
     * @param float $score Raw weighted score
     * @return float Normalized score (0-1)
     */
    public function normalizeScore(float $score): float
    {
        // Typical max weighted score would be around 2.0 (if team scores 2 goals every HT)
        // We normalize to 0-1 range
        $normalized = $score / 2.0;
        return max(0.0, min(1.0, $normalized));
    }

    /**
     * Check if weighted form data is available and valid.
     *
     * @param array<string,mixed> $weightedData
     * @return bool
     */
    public function hasValidData(array $weightedData): bool
    {
        return ($weightedData['has_data'] ?? false) === true
            && $weightedData['home'] !== null
            && $weightedData['away'] !== null
            && $weightedData['score'] !== null;
    }
}
