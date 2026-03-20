<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

/**
 * Filters matches based on betting rules and criteria.
 */
final class MatchFilter
{
    private const MIN_MINUTE = 15;
    private const MAX_MINUTE = 30;
    private const DEFAULT_MIN_PROBABILITY = 0.65;
    private const MIN_DANGEROUS_ATTACKS = 20;

    public function __construct(
        private float $minProbability = self::DEFAULT_MIN_PROBABILITY,
    ) {
    }

    /**
     * Check if match is in valid time window (15-30 minutes).
     */
    public function isInTimeWindow(int $minute): bool
    {
        return $minute >= self::MIN_MINUTE && $minute <= self::MAX_MINUTE;
    }

    /**
     * Check if match has minimum required activity.
     *
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     */
    public function hasMinimumActivity(array $liveData): bool
    {
        return $liveData['shots_on_target'] > 0 
            && $liveData['dangerous_attacks'] >= self::MIN_DANGEROUS_ATTACKS;
    }

    /**
     * Check if first half is still goalless.
     *
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     */
    public function isFirstHalfGoalless(array $liveData): bool
    {
        return $liveData['ht_hscore'] === 0 && $liveData['ht_ascore'] === 0;
    }

    /**
     * Check if probability meets minimum threshold.
     */
    public function meetsMinimumProbability(float $probability): bool
    {
        return $probability >= $this->minProbability;
    }

    /**
     * Determine if bet should be placed and provide reason.
     *
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     * @param float $probability
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @return array{bet:bool,reason:string}
     */
    public function shouldBet(array $liveData, float $probability, array $formData, array $h2hData): array
    {
        $minute = $liveData['minute'];

        // Check for insufficient data
        if (!$formData['has_data']) {
            return [
                'bet' => false,
                'reason' => 'Недостаточно данных по форме',
            ];
        }

        if (!$h2hData['has_data']) {
            return [
                'bet' => false,
                'reason' => 'Недостаточно данных по H2H',
            ];
        }

        // Check if goal already scored
        if (!$this->isFirstHalfGoalless($liveData)) {
            return [
                'bet' => false,
                'reason' => 'Гол уже забит в первом тайме',
            ];
        }

        // Check time window
        if ($minute < self::MIN_MINUTE) {
            return [
                'bet' => false,
                'reason' => sprintf('Слишком рано (минута %d)', $minute),
            ];
        }

        if ($minute > self::MAX_MINUTE) {
            return [
                'bet' => false,
                'reason' => sprintf('Слишком поздно (минута %d)', $minute),
            ];
        }

        // Check minimum activity
        if ($liveData['shots_on_target'] === 0) {
            return [
                'bet' => false,
                'reason' => 'Нет ударов в створ',
            ];
        }

        if ($liveData['dangerous_attacks'] < self::MIN_DANGEROUS_ATTACKS) {
            return [
                'bet' => false,
                'reason' => sprintf('Мало опасных атак (%d)', $liveData['dangerous_attacks']),
            ];
        }

        // Check probability threshold
        if (!$this->meetsMinimumProbability($probability)) {
            return [
                'bet' => false,
                'reason' => sprintf('Вероятность ниже порога (%.0f%%)', $probability * 100),
            ];
        }

        // All checks passed
        return [
            'bet' => true,
            'reason' => sprintf(
                'Высокая вероятность (%.0f%%), активная игра',
                $probability * 100
            ),
        ];
    }
}
