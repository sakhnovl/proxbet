<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Filters;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

/**
 * Legacy filter for Algorithm One (v1).
 * Determines whether a bet should be placed based on legacy criteria.
 */
final class LegacyFilter
{
    public function __construct(
        private float $minProbability = Config::MIN_PROBABILITY,
    ) {
    }

    /**
     * Determine if a bet should be placed using legacy Algorithm One criteria.
     *
     * @param array{
     *   minute:int,
     *   shots_total:int,
     *   shots_on_target:int,
     *   dangerous_attacks:int,
     *   corners:int,
     *   ht_hscore:int,
     *   ht_ascore:int,
     *   live_hscore:int,
     *   live_ascore:int,
     *   time_str:string,
     *   match_status:string
     * } $liveData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @return array{bet:bool,reason:string}
     */
    public function shouldBet(array $liveData, float $probability, array $formData, array $h2hData): array
    {
        $minute = $liveData['minute'];

        // Check 1: Form data availability
        if (!$formData['has_data']) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 1: недостаточно данных по форме',
            ];
        }

        // Check 2: H2H data availability
        if (!$h2hData['has_data']) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 1: недостаточно данных по H2H',
            ];
        }

        // Check 3: First half must be goalless (0:0)
        if (!$this->isFirstHalfGoalless($liveData)) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 1: гол уже забит в первом тайме',
            ];
        }

        // Check 4: Minute must be >= 15
        if ($minute < Config::MIN_MINUTE) {
            return [
                'bet' => false,
                'reason' => sprintf('Алгоритм 1: слишком рано (минута %d)', $minute),
            ];
        }

        // Check 5: Minute must be <= 30
        if ($minute > Config::MAX_MINUTE) {
            return [
                'bet' => false,
                'reason' => sprintf('Алгоритм 1: слишком поздно (минута %d)', $minute),
            ];
        }

        // Check 6: Must have at least 1 shot on target
        if ($liveData['shots_on_target'] === 0) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 1: нет ударов в створ',
            ];
        }

        // Check 7: Must have at least 20 dangerous attacks
        if ($liveData['dangerous_attacks'] < Config::MIN_DANGEROUS_ATTACKS) {
            return [
                'bet' => false,
                'reason' => sprintf('Алгоритм 1: мало опасных атак (%d)', $liveData['dangerous_attacks']),
            ];
        }

        // Check 8: Probability must meet minimum threshold
        if (!$this->meetsMinimumProbability($probability)) {
            return [
                'bet' => false,
                'reason' => sprintf('Алгоритм 1: вероятность ниже порога (%.0f%%)', $probability * 100),
            ];
        }

        // All checks passed
        return [
            'bet' => true,
            'reason' => sprintf(
                'Алгоритм 1: высокая вероятность (%.0f%%), активная игра',
                $probability * 100
            ),
        ];
    }

    /**
     * Check if first half is goalless (0:0).
     *
     * @param array{
     *   minute:int,
     *   shots_total:int,
     *   shots_on_target:int,
     *   dangerous_attacks:int,
     *   corners:int,
     *   ht_hscore:int,
     *   ht_ascore:int,
     *   live_hscore:int,
     *   live_ascore:int,
     *   time_str:string,
     *   match_status:string
     * } $liveData
     */
    private function isFirstHalfGoalless(array $liveData): bool
    {
        return $liveData['ht_hscore'] === 0 && $liveData['ht_ascore'] === 0;
    }

    /**
     * Check if probability meets minimum threshold.
     */
    private function meetsMinimumProbability(float $probability): bool
    {
        return $probability >= $this->minProbability;
    }
}
