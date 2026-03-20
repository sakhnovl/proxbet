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
    private const MAX_HOME_WIN_ODD = 1.5;
    private const MAX_OVER_25_ODD = 1.5;
    private const MIN_HOME_FIRST_HALF_GOALS = 3;
    private const MIN_H2H_FIRST_HALF_GOALS = 3;

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
     * Determine if bet should be placed and provide reason for algorithm 1.
     *
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     * @param float $probability
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @return array{bet:bool,reason:string}
     */
    public function shouldBet(array $liveData, float $probability, array $formData, array $h2hData): array
    {
        return $this->shouldBetAlgorithmOne($liveData, $probability, $formData, $h2hData);
    }

    /**
     * Determine if bet should be placed and provide reason for algorithm 1.
     *
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     * @param float $probability
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @return array{bet:bool,reason:string}
     */
    public function shouldBetAlgorithmOne(array $liveData, float $probability, array $formData, array $h2hData): array
    {
        $minute = $liveData['minute'];

        if (!$formData['has_data']) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 1: недостаточно данных по форме',
            ];
        }

        if (!$h2hData['has_data']) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 1: недостаточно данных по H2H',
            ];
        }

        if (!$this->isFirstHalfGoalless($liveData)) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 1: гол уже забит в первом тайме',
            ];
        }

        if ($minute < self::MIN_MINUTE) {
            return [
                'bet' => false,
                'reason' => sprintf('Алгоритм 1: слишком рано (минута %d)', $minute),
            ];
        }

        if ($minute > self::MAX_MINUTE) {
            return [
                'bet' => false,
                'reason' => sprintf('Алгоритм 1: слишком поздно (минута %d)', $minute),
            ];
        }

        if ($liveData['shots_on_target'] === 0) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 1: нет ударов в створ',
            ];
        }

        if ($liveData['dangerous_attacks'] < self::MIN_DANGEROUS_ATTACKS) {
            return [
                'bet' => false,
                'reason' => sprintf('Алгоритм 1: мало опасных атак (%d)', $liveData['dangerous_attacks']),
            ];
        }

        if (!$this->meetsMinimumProbability($probability)) {
            return [
                'bet' => false,
                'reason' => sprintf('Алгоритм 1: вероятность ниже порога (%.0f%%)', $probability * 100),
            ];
        }

        return [
            'bet' => true,
            'reason' => sprintf(
                'Алгоритм 1: высокая вероятность (%.0f%%), активная игра',
                $probability * 100
            ),
        ];
    }

    /**
     * Determine if bet should be placed and provide reason for algorithm 2.
     *
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     * @param array{
     *   home_win_odd:float,
     *   over_25_odd:float|null,
     *   total_line:float|null,
     *   over_25_odd_check_skipped:bool,
     *   home_first_half_goals_in_last_5:int,
     *   h2h_first_half_goals_in_last_5:int,
     *   has_data:bool
     * } $algorithmTwoData
     * @return array{bet:bool,reason:string}
     */
    public function shouldBetAlgorithmTwo(array $liveData, array $algorithmTwoData): array
    {
        $minute = $liveData['minute'];

        if (!$this->isFirstHalfGoalless($liveData)) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 2: гол уже забит в первом тайме',
            ];
        }

        if ($minute < self::MIN_MINUTE) {
            return [
                'bet' => false,
                'reason' => sprintf('Алгоритм 2: слишком рано (минута %d)', $minute),
            ];
        }

        if ($minute > self::MAX_MINUTE) {
            return [
                'bet' => false,
                'reason' => sprintf('Алгоритм 2: слишком поздно (минута %d)', $minute),
            ];
        }

        if (!$algorithmTwoData['has_data']) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 2: недостаточно данных по коэффициентам или статистике',
            ];
        }

        if ($algorithmTwoData['home_win_odd'] > self::MAX_HOME_WIN_ODD) {
            return [
                'bet' => false,
                'reason' => sprintf(
                    'Алгоритм 2: коэффициент на хозяев выше порога (%.2f)',
                    $algorithmTwoData['home_win_odd']
                ),
            ];
        }

        if (
            !$algorithmTwoData['over_25_odd_check_skipped']
            && $algorithmTwoData['over_25_odd'] !== null
            && $algorithmTwoData['over_25_odd'] > self::MAX_OVER_25_ODD
        ) {
            return [
                'bet' => false,
                'reason' => sprintf(
                    'Алгоритм 2: коэффициент на ТБ 2.5 выше порога (%.2f)',
                    $algorithmTwoData['over_25_odd']
                ),
            ];
        }

        if ($algorithmTwoData['home_first_half_goals_in_last_5'] < self::MIN_HOME_FIRST_HALF_GOALS) {
            return [
                'bet' => false,
                'reason' => sprintf(
                    'Алгоритм 2: домашняя команда редко забивает в 1 тайме (%d/5)',
                    $algorithmTwoData['home_first_half_goals_in_last_5']
                ),
            ];
        }

        if ($algorithmTwoData['h2h_first_half_goals_in_last_5'] < self::MIN_H2H_FIRST_HALF_GOALS) {
            return [
                'bet' => false,
                'reason' => sprintf(
                    'Алгоритм 2: H2H не подтверждает голы в 1 тайме (%d/5)',
                    $algorithmTwoData['h2h_first_half_goals_in_last_5']
                ),
            ];
        }

        return [
            'bet' => true,
            'reason' => $this->buildAlgorithmTwoSuccessReason($minute, $algorithmTwoData),
        ];
    }

    /**
     * @param array{
     *   home_win_odd:float,
     *   over_25_odd:float|null,
     *   total_line:float|null,
     *   over_25_odd_check_skipped:bool,
     *   home_first_half_goals_in_last_5:int,
     *   h2h_first_half_goals_in_last_5:int,
     *   has_data:bool
     * } $algorithmTwoData
     */
    private function buildAlgorithmTwoSuccessReason(int $minute, array $algorithmTwoData): string
    {
        $overLineText = $algorithmTwoData['over_25_odd_check_skipped']
            ? sprintf('линия %.2f > 2.5, коэффициент ТБ 2.5 не проверяется', (float) ($algorithmTwoData['total_line'] ?? 0.0))
            : sprintf('ТБ 2.5 %.2f', (float) ($algorithmTwoData['over_25_odd'] ?? 0.0));

        return sprintf(
            'Алгоритм 2: выполнены все условия (0:0, %d мин, П1 %.2f, %s, форма %d/5, H2H %d/5)',
            $minute,
            $algorithmTwoData['home_win_odd'],
            $overLineText,
            $algorithmTwoData['home_first_half_goals_in_last_5'],
            $algorithmTwoData['h2h_first_half_goals_in_last_5']
        );
    }
}
