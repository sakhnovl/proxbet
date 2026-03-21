<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

/**
 * Filters matches based on betting rules and criteria.
 * 
 * @deprecated This class is deprecated and will be removed in a future version.
 *             For Algorithm 1 filtering, use Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter.
 *             Algorithm 2 and 3 filtering logic remains in this class for now.
 *             
 * Migration path:
 * - Algorithm 1: Use AlgorithmOne\Filters\LegacyFilter::shouldBet()
 * - Algorithm 2/3: Continue using this class (no migration yet)
 */
final class MatchFilter
{
    private const MIN_MINUTE = 15;
    private const MAX_MINUTE = 30;
    private const ALGORITHM_THREE_RATIO_THRESHOLD = 1.1;
    private const ALGORITHM_THREE_MIN_GAMES = 10;
    private const DEFAULT_MIN_PROBABILITY = 0.55;
    private const MIN_DANGEROUS_ATTACKS = 20;
    private const MAX_HOME_WIN_ODD = 1.5;
    private const MAX_OVER_25_ODD = 1.5;
    private const MIN_HOME_FIRST_HALF_GOALS = 3;
    private const MIN_H2H_FIRST_HALF_GOALS = 3;

    public function __construct(
        private float $minProbability = self::DEFAULT_MIN_PROBABILITY,
    ) {
    }

    public function isInTimeWindow(int $minute): bool
    {
        return $minute >= self::MIN_MINUTE && $minute <= self::MAX_MINUTE;
    }

    /**
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
    public function hasMinimumActivity(array $liveData): bool
    {
        return $liveData['shots_on_target'] > 0
            && $liveData['dangerous_attacks'] >= self::MIN_DANGEROUS_ATTACKS;
    }

    /**
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
    public function isFirstHalfGoalless(array $liveData): bool
    {
        return $liveData['ht_hscore'] === 0 && $liveData['ht_ascore'] === 0;
    }

    public function meetsMinimumProbability(float $probability): bool
    {
        return $probability >= $this->minProbability;
    }

    /**
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
        return $this->shouldBetAlgorithmOne($liveData, $probability, $formData, $h2hData);
    }

    /**
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
     *   table_games_1:int,
     *   table_goals_1:int,
     *   table_missed_1:int,
     *   table_games_2:int,
     *   table_goals_2:int,
     *   table_missed_2:int,
     *   live_hscore:int,
     *   live_ascore:int,
     *   match_status:string,
     *   home:string,
     *   away:string,
     *   has_data:bool
     * } $algorithmThreeData
     * @return array<string,mixed>
     */
    public function shouldBetAlgorithmThree(array $algorithmThreeData): array
    {
        if (!$algorithmThreeData['has_data']) {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 3: недостаточно табличных данных',
            ];
        }

        if (
            $algorithmThreeData['table_games_1'] <= self::ALGORITHM_THREE_MIN_GAMES
            || $algorithmThreeData['table_games_2'] <= self::ALGORITHM_THREE_MIN_GAMES
        ) {
            return [
                'bet' => false,
                'reason' => sprintf(
                    'Алгоритм 3: мало игр в таблице (%d/%d)',
                    $algorithmThreeData['table_games_1'],
                    $algorithmThreeData['table_games_2']
                ),
            ];
        }

        $homeAttackRatio = $this->calculateAlgorithmThreeRatio(
            $algorithmThreeData['table_goals_1'],
            $algorithmThreeData['table_games_1']
        );
        $awayDefenseRatio = $this->calculateAlgorithmThreeRatio(
            $algorithmThreeData['table_missed_2'],
            $algorithmThreeData['table_games_2']
        );
        $awayAttackRatio = $this->calculateAlgorithmThreeRatio(
            $algorithmThreeData['table_goals_2'],
            $algorithmThreeData['table_games_2']
        );
        $homeDefenseRatio = $this->calculateAlgorithmThreeRatio(
            $algorithmThreeData['table_missed_1'],
            $algorithmThreeData['table_games_1']
        );

        $homeCandidate = $homeAttackRatio >= self::ALGORITHM_THREE_RATIO_THRESHOLD
            && $awayDefenseRatio >= self::ALGORITHM_THREE_RATIO_THRESHOLD;
        $awayCandidate = $awayAttackRatio >= self::ALGORITHM_THREE_RATIO_THRESHOLD
            && $homeDefenseRatio >= self::ALGORITHM_THREE_RATIO_THRESHOLD;

        if (!$homeCandidate && !$awayCandidate) {
            return [
                'bet' => false,
                'reason' => sprintf(
                    'Алгоритм 3: формула не выполнена (home %.2f/%.2f, away %.2f/%.2f)',
                    $homeAttackRatio,
                    $awayDefenseRatio,
                    $awayAttackRatio,
                    $homeDefenseRatio
                ),
            ];
        }

        $selectedTeam = $this->selectAlgorithmThreeTeam(
            $algorithmThreeData,
            $homeCandidate,
            $awayCandidate,
            $homeAttackRatio,
            $awayDefenseRatio,
            $awayAttackRatio,
            $homeDefenseRatio
        );

        if ($selectedTeam['current_goals'] > 0) {
            return [
                'bet' => false,
                'reason' => sprintf(
                    'Алгоритм 3: %s уже забила (%d)',
                    $selectedTeam['selected_team_name'],
                    $selectedTeam['current_goals']
                ),
                'selected_team_side' => $selectedTeam['selected_team_side'],
                'selected_team_name' => $selectedTeam['selected_team_name'],
                'selected_team_goals_current' => $selectedTeam['current_goals'],
                'selected_team_target_bet' => $selectedTeam['selected_team_target_bet'],
            ];
        }

        if (trim($algorithmThreeData['match_status']) !== 'Перерыв') {
            return [
                'bet' => false,
                'reason' => 'Алгоритм 3: сигнал отправляется только в перерыве',
                'selected_team_side' => $selectedTeam['selected_team_side'],
                'selected_team_name' => $selectedTeam['selected_team_name'],
                'selected_team_goals_current' => $selectedTeam['current_goals'],
                'selected_team_target_bet' => $selectedTeam['selected_team_target_bet'],
            ];
        }

        return [
            'bet' => true,
            'reason' => $this->buildAlgorithmThreeSuccessReason(
                $selectedTeam,
                $homeAttackRatio,
                $awayDefenseRatio,
                $awayAttackRatio,
                $homeDefenseRatio
            ),
            'selected_team_side' => $selectedTeam['selected_team_side'],
            'selected_team_name' => $selectedTeam['selected_team_name'],
            'selected_team_goals_current' => $selectedTeam['current_goals'],
            'selected_team_target_bet' => $selectedTeam['selected_team_target_bet'],
            'triggered_rule' => $selectedTeam['triggered_rule'],
            'triggered_rule_label' => $selectedTeam['triggered_rule_label'],
            'home_attack_ratio' => $homeAttackRatio,
            'away_defense_ratio' => $awayDefenseRatio,
            'away_attack_ratio' => $awayAttackRatio,
            'home_defense_ratio' => $homeDefenseRatio,
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

    private function calculateAlgorithmThreeRatio(int $goalsOrMissed, int $games): float
    {
        if ($games <= 0) {
            return 0.0;
        }

        return ($goalsOrMissed / 2) / $games;
    }

    /**
     * @param array{
     *   table_games_1:int,
     *   table_goals_1:int,
     *   table_missed_1:int,
     *   table_games_2:int,
     *   table_goals_2:int,
     *   table_missed_2:int,
     *   live_hscore:int,
     *   live_ascore:int,
     *   match_status:string,
     *   home:string,
     *   away:string,
     *   has_data:bool
     * } $algorithmThreeData
     * @return array<string,mixed>
     */
    private function selectAlgorithmThreeTeam(
        array $algorithmThreeData,
        bool $homeCandidate,
        bool $awayCandidate,
        float $homeAttackRatio,
        float $awayDefenseRatio,
        float $awayAttackRatio,
        float $homeDefenseRatio
    ): array {
        $homeStrength = $homeAttackRatio + $awayDefenseRatio;
        $awayStrength = $awayAttackRatio + $homeDefenseRatio;

        $pickHome = $homeCandidate && (!$awayCandidate || $homeStrength >= $awayStrength);
        $selectedTeamSide = $pickHome ? 'home' : 'away';
        $selectedTeamName = $pickHome ? $algorithmThreeData['home'] : $algorithmThreeData['away'];
        $currentGoals = $pickHome ? $algorithmThreeData['live_hscore'] : $algorithmThreeData['live_ascore'];
        $triggeredRule = $pickHome ? 'team_1_attack_vs_team_2_missed' : 'team_2_attack_vs_team_1_missed';

        if ($homeCandidate && $awayCandidate) {
            $triggeredRule = $pickHome
                ? 'both_rules_matched_selected_team_1'
                : 'both_rules_matched_selected_team_2';
        }

        $triggeredRuleLabel = match ($triggeredRule) {
            'team_1_attack_vs_team_2_missed' => 'атака хозяев и пропускаемость гостей выше порога',
            'team_2_attack_vs_team_1_missed' => 'атака гостей и пропускаемость хозяев выше порога',
            'both_rules_matched_selected_team_1' => 'обе формулы прошли, хозяева выбраны по более сильной сумме коэффициентов',
            'both_rules_matched_selected_team_2' => 'обе формулы прошли, гости выбраны по более сильной сумме коэффициентов',
            default => 'табличная формула алгоритма 3 выполнена',
        };

        return [
            'selected_team_side' => $selectedTeamSide,
            'selected_team_name' => $selectedTeamName,
            'selected_team_target_bet' => 'ИТБ ' . $selectedTeamName . ' больше 0.5',
            'current_goals' => $currentGoals,
            'triggered_rule' => $triggeredRule,
            'triggered_rule_label' => $triggeredRuleLabel,
        ];
    }

    /**
     * @param array<string,mixed> $selectedTeam
     */
    private function buildAlgorithmThreeSuccessReason(
        array $selectedTeam,
        float $homeAttackRatio,
        float $awayDefenseRatio,
        float $awayAttackRatio,
        float $homeDefenseRatio
    ): string
    {
        $ratioSummary = sprintf(
            'home attack %.2f, away defense %.2f, away attack %.2f, home defense %.2f',
            $homeAttackRatio,
            $awayDefenseRatio,
            $awayAttackRatio,
            $homeDefenseRatio
        );

        return sprintf(
            'Алгоритм 3: выбрана %s, потому что %s; ставка %s; матч в перерыве, команда еще не забила; коэффициенты: %s',
            $selectedTeam['selected_team_name'],
            $selectedTeam['triggered_rule_label'] ?? 'табличные условия выполнены',
            $selectedTeam['selected_team_target_bet'],
            $ratioSummary
        );
    }
}
