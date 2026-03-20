<?php

declare(strict_types=1);

require_once __DIR__ . '/../scanner/ProbabilityCalculator.php';
require_once __DIR__ . '/../scanner/MatchFilter.php';

use Proxbet\Scanner\MatchFilter;
use Proxbet\Scanner\ProbabilityCalculator;

function buildFallbackAnalysisContextFromCallback(array $cq, int $matchId): ?array
{
    $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];
    $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
    if ($text === '') {
        return null;
    }

    $home = null;
    $away = null;
    if (preg_match('/⚽\s*<b>(.+?)\s*-\s*(.+?)<\/b>/u', $text, $m) === 1) {
        $home = trim($m[1]);
        $away = trim($m[2]);
    }

    $liga = null;
    if (preg_match('/🏆\s*(.+)/u', $text, $m) === 1) {
        $liga = trim($m[1]);
    }

    $time = null;
    if (preg_match('/⏱\s*Время:\s*<b>([0-9:]+)<\/b>/u', $text, $m) === 1) {
        $time = trim($m[1]);
    }

    $scoreHome = null;
    $scoreAway = null;
    if (preg_match('/⚽\s*Счет:\s*<b>([0-9]+):([0-9]+)<\/b>/u', $text, $m) === 1) {
        $scoreHome = (int) $m[1];
        $scoreAway = (int) $m[2];
    }

    return [
        'match_id' => $matchId,
        'country' => null,
        'liga' => $liga,
        'home' => $home,
        'away' => $away,
        'time' => $time,
        'match_status' => 'Тестовый/ручной пост',
        'start_time' => null,
        'live_ht_hscore' => $scoreHome,
        'live_ht_ascore' => $scoreAway,
        'live_hscore' => $scoreHome,
        'live_ascore' => $scoreAway,
        'home_cf' => null,
        'draw_cf' => null,
        'away_cf' => null,
        'total_line' => null,
        'total_line_tb' => null,
        'total_line_tm' => null,
        'ht_match_goals_1' => null,
        'ht_match_goals_2' => null,
        'h2h_ht_match_goals_1' => null,
        'h2h_ht_match_goals_2' => null,
        'live_xg_home' => null,
        'live_xg_away' => null,
        'live_att_home' => null,
        'live_att_away' => null,
        'live_danger_att_home' => null,
        'live_danger_att_away' => null,
        'live_shots_on_target_home' => null,
        'live_shots_on_target_away' => null,
        'live_corner_home' => null,
        'live_corner_away' => null,
        'bet_message_id' => null,
        'message_text' => $text,
        'bet_sent_at' => null,
    ];
}

function enrichAnalysisContextWithScanner(array $context): array
{
    $calculator = new ProbabilityCalculator();
    $filter = new MatchFilter();
    $algorithmId = max(1, (int) ($context['algorithm_id'] ?? 1));

    $formData = [
        'home_goals' => normalizeInt($context['ht_match_goals_1'] ?? null),
        'away_goals' => normalizeInt($context['ht_match_goals_2'] ?? null),
        'has_data' => ($context['ht_match_goals_1'] ?? null) !== null && ($context['ht_match_goals_2'] ?? null) !== null,
    ];
    $h2hData = [
        'home_goals' => normalizeInt($context['h2h_ht_match_goals_1'] ?? null),
        'away_goals' => normalizeInt($context['h2h_ht_match_goals_2'] ?? null),
        'has_data' => ($context['h2h_ht_match_goals_1'] ?? null) !== null && ($context['h2h_ht_match_goals_2'] ?? null) !== null,
    ];

    $shotsOnTargetHome = normalizeFloat($context['live_shots_on_target_home'] ?? null);
    $shotsOnTargetAway = normalizeFloat($context['live_shots_on_target_away'] ?? null);
    $shotsOffTargetHome = normalizeFloat($context['live_shots_off_target_home'] ?? null);
    $shotsOffTargetAway = normalizeFloat($context['live_shots_off_target_away'] ?? null);
    $dangerAttHome = normalizeFloat($context['live_danger_att_home'] ?? null);
    $dangerAttAway = normalizeFloat($context['live_danger_att_away'] ?? null);
    $cornerHome = normalizeFloat($context['live_corner_home'] ?? null);
    $cornerAway = normalizeFloat($context['live_corner_away'] ?? null);

    $liveData = [
        'minute' => extractMinuteFromTime((string) ($context['time'] ?? '')),
        'shots_total' => (int) ($shotsOnTargetHome + $shotsOnTargetAway + $shotsOffTargetHome + $shotsOffTargetAway),
        'shots_on_target' => (int) ($shotsOnTargetHome + $shotsOnTargetAway),
        'dangerous_attacks' => (int) ($dangerAttHome + $dangerAttAway),
        'corners' => (int) ($cornerHome + $cornerAway),
        'shots_on_target_home' => (int) $shotsOnTargetHome,
        'shots_on_target_away' => (int) $shotsOnTargetAway,
        'shots_off_target_home' => (int) $shotsOffTargetHome,
        'shots_off_target_away' => (int) $shotsOffTargetAway,
        'dangerous_attacks_home' => (int) $dangerAttHome,
        'dangerous_attacks_away' => (int) $dangerAttAway,
        'corners_home' => (int) $cornerHome,
        'corners_away' => (int) $cornerAway,
        'xg_home' => ($context['live_xg_home'] ?? null) !== null ? normalizeFloat($context['live_xg_home']) : null,
        'xg_away' => ($context['live_xg_away'] ?? null) !== null ? normalizeFloat($context['live_xg_away']) : null,
        'yellow_cards_home' => ($context['live_yellow_cards_home'] ?? null) !== null ? normalizeInt($context['live_yellow_cards_home']) : null,
        'yellow_cards_away' => ($context['live_yellow_cards_away'] ?? null) !== null ? normalizeInt($context['live_yellow_cards_away']) : null,
        'trend_shots_total_delta' => ($context['live_trend_shots_total_delta'] ?? null) !== null ? normalizeInt($context['live_trend_shots_total_delta']) : null,
        'trend_shots_on_target_delta' => ($context['live_trend_shots_on_target_delta'] ?? null) !== null ? normalizeInt($context['live_trend_shots_on_target_delta']) : null,
        'trend_dangerous_attacks_delta' => ($context['live_trend_danger_attacks_delta'] ?? null) !== null ? normalizeInt($context['live_trend_danger_attacks_delta']) : null,
        'trend_xg_delta' => ($context['live_trend_xg_delta'] ?? null) !== null ? normalizeFloat($context['live_trend_xg_delta']) : null,
        'trend_window_seconds' => ($context['live_trend_window_seconds'] ?? null) !== null ? normalizeInt($context['live_trend_window_seconds']) : null,
        'has_trend_data' => normalizeInt($context['live_trend_has_data'] ?? null) === 1,
        'ht_hscore' => normalizeInt($context['live_ht_hscore'] ?? null),
        'ht_ascore' => normalizeInt($context['live_ht_ascore'] ?? null),
        'live_hscore' => normalizeInt($context['live_hscore'] ?? null),
        'live_ascore' => normalizeInt($context['live_ascore'] ?? null),
        'time_str' => (string) ($context['time'] ?? ''),
        'match_status' => (string) ($context['match_status'] ?? ''),
    ];

    if ($algorithmId === 3) {
        $algorithmThreeData = buildAlgorithmThreeContextData($context);
        $decision = $filter->shouldBetAlgorithmThree($algorithmThreeData);
        $context['scanner_form_score'] = null;
        $context['scanner_h2h_score'] = null;
        $context['scanner_live_score'] = null;
        $context['scanner_probability'] = null;
        $context['scanner_bet'] = $decision['bet'] ? 'yes' : 'no';
        $context['scanner_reason'] = $decision['reason'];
        $context['scanner_signal_type'] = 'team_total';
        $context['scanner_algorithm_basis'] = 'table_rules';
        $context['scanner_algorithm_data'] = array_merge($algorithmThreeData, [
            'selected_team_side' => $decision['selected_team_side'] ?? ($algorithmThreeData['selected_team_side'] ?? null),
            'selected_team_name' => $decision['selected_team_name'] ?? ($algorithmThreeData['selected_team_name'] ?? null),
            'selected_team_goals_current' => $decision['selected_team_goals_current'] ?? ($algorithmThreeData['selected_team_goals_current'] ?? null),
            'selected_team_target_bet' => $decision['selected_team_target_bet'] ?? ($algorithmThreeData['selected_team_target_bet'] ?? null),
            'triggered_rule' => $decision['triggered_rule'] ?? ($algorithmThreeData['triggered_rule'] ?? null),
            'triggered_rule_label' => $decision['triggered_rule_label'] ?? ($algorithmThreeData['triggered_rule_label'] ?? null),
            'home_attack_ratio' => $decision['home_attack_ratio'] ?? ($algorithmThreeData['home_attack_ratio'] ?? null),
            'away_defense_ratio' => $decision['away_defense_ratio'] ?? ($algorithmThreeData['away_defense_ratio'] ?? null),
            'away_attack_ratio' => $decision['away_attack_ratio'] ?? ($algorithmThreeData['away_attack_ratio'] ?? null),
            'home_defense_ratio' => $decision['home_defense_ratio'] ?? ($algorithmThreeData['home_defense_ratio'] ?? null),
        ]);
        return $context;
    }

    if ($algorithmId === 2) {
        $algorithmTwoData = buildAlgorithmTwoContextData($context);
        $decision = $filter->shouldBetAlgorithmTwo($liveData, $algorithmTwoData);
        $context['scanner_form_score'] = null;
        $context['scanner_h2h_score'] = null;
        $context['scanner_live_score'] = null;
        $context['scanner_probability'] = null;
        $context['scanner_bet'] = $decision['bet'] ? 'yes' : 'no';
        $context['scanner_reason'] = $decision['reason'];
        $context['scanner_signal_type'] = 'favorite_first_half_goal';
        $context['scanner_algorithm_basis'] = 'rule_based';
        $context['scanner_algorithm_data'] = $algorithmTwoData;
        return $context;
    }

    $scores = $calculator->calculateAll($formData, $h2hData, $liveData);
    $decision = $filter->shouldBet($liveData, $scores['probability'], $formData, $h2hData);
    $context['scanner_form_score'] = round($scores['form_score'], 2);
    $context['scanner_h2h_score'] = round($scores['h2h_score'], 2);
    $context['scanner_live_score'] = round($scores['live_score'], 2);
    $context['scanner_probability'] = round($scores['probability'] * 100);
    $context['scanner_bet'] = $decision['bet'] ? 'yes' : 'no';
    $context['scanner_reason'] = $decision['reason'];

    return $context;
}

function buildAlgorithmTwoContextData(array $context): array
{
    $homeWinOdd = is_numeric($context['home_cf'] ?? null) ? (float) $context['home_cf'] : null;
    $totalLine = is_numeric($context['total_line'] ?? null) ? (float) $context['total_line'] : null;
    $over25Odd = null;
    $over25OddCheckSkipped = false;

    if ($totalLine !== null && abs($totalLine - 2.5) < 0.001) {
        $over25Odd = is_numeric($context['total_line_tb'] ?? null) ? (float) $context['total_line_tb'] : null;
    } elseif ($totalLine !== null && $totalLine > 2.5) {
        $over25OddCheckSkipped = true;
    }

    $homeFirstHalfGoals = is_numeric($context['ht_match_goals_1'] ?? null) ? (int) $context['ht_match_goals_1'] : null;
    $h2hFirstHalfGoals = extractAlgorithmTwoH2hFirstHalfGoals($context['sgi_json'] ?? null);
    $hasData = $homeWinOdd !== null
        && ($over25Odd !== null || $over25OddCheckSkipped)
        && $homeFirstHalfGoals !== null
        && $h2hFirstHalfGoals !== null;

    return [
        'home_win_odd' => $homeWinOdd ?? 0.0,
        'over_25_odd' => $over25Odd,
        'total_line' => $totalLine,
        'over_25_odd_check_skipped' => $over25OddCheckSkipped,
        'home_first_half_goals_in_last_5' => $homeFirstHalfGoals ?? 0,
        'h2h_first_half_goals_in_last_5' => $h2hFirstHalfGoals ?? 0,
        'has_data' => $hasData,
    ];
}

function buildAlgorithmThreeContextData(array $context): array
{
    $payload = [];
    $rawPayload = $context['algorithm_payload_json'] ?? null;
    if (is_string($rawPayload) && trim($rawPayload) !== '') {
        $decoded = json_decode($rawPayload, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $tableGames1 = is_numeric($context['table_games_1'] ?? null) ? (int) $context['table_games_1'] : null;
    $tableGoals1 = is_numeric($context['table_goals_1'] ?? null) ? (int) $context['table_goals_1'] : null;
    $tableMissed1 = is_numeric($context['table_missed_1'] ?? null) ? (int) $context['table_missed_1'] : null;
    $tableGames2 = is_numeric($context['table_games_2'] ?? null) ? (int) $context['table_games_2'] : null;
    $tableGoals2 = is_numeric($context['table_goals_2'] ?? null) ? (int) $context['table_goals_2'] : null;
    $tableMissed2 = is_numeric($context['table_missed_2'] ?? null) ? (int) $context['table_missed_2'] : null;

    $selectedTeamSide = is_string($payload['selected_team_side'] ?? null) ? $payload['selected_team_side'] : null;
    $selectedTeamName = is_string($payload['selected_team_name'] ?? null) ? $payload['selected_team_name'] : null;
    $selectedTeamBet = is_string($payload['selected_team_target_bet'] ?? null) ? $payload['selected_team_target_bet'] : null;

    if ($selectedTeamSide === null || $selectedTeamName === null || $selectedTeamBet === null) {
        $homeAttackRatio = calculateAlgorithmThreeRatio($tableGoals1, $tableGames1);
        $awayDefenseRatio = calculateAlgorithmThreeRatio($tableMissed2, $tableGames2);
        $awayAttackRatio = calculateAlgorithmThreeRatio($tableGoals2, $tableGames2);
        $homeDefenseRatio = calculateAlgorithmThreeRatio($tableMissed1, $tableGames1);
        $threshold = 1.5;
        $homeRuleMatched = $homeAttackRatio > $threshold && $awayDefenseRatio > $threshold;
        $awayRuleMatched = $awayAttackRatio > $threshold && $homeDefenseRatio > $threshold;

        if ($homeRuleMatched && !$awayRuleMatched) {
            $selectedTeamSide = 'home';
            $selectedTeamName = (string) ($context['home'] ?? '');
        } elseif ($awayRuleMatched && !$homeRuleMatched) {
            $selectedTeamSide = 'away';
            $selectedTeamName = (string) ($context['away'] ?? '');
        } elseif ($homeRuleMatched && $awayRuleMatched) {
            if ($homeAttackRatio + $awayDefenseRatio >= $awayAttackRatio + $homeDefenseRatio) {
                $selectedTeamSide = 'home';
                $selectedTeamName = (string) ($context['home'] ?? '');
            } else {
                $selectedTeamSide = 'away';
                $selectedTeamName = (string) ($context['away'] ?? '');
            }
        }

        if ($selectedTeamSide !== null && $selectedTeamName !== null) {
            $selectedTeamBet = 'ИТБ ' . $selectedTeamName . ' больше 0.5';
        }
    }

    return [
        'table_games_1' => $tableGames1,
        'table_goals_1' => $tableGoals1,
        'table_missed_1' => $tableMissed1,
        'table_games_2' => $tableGames2,
        'table_goals_2' => $tableGoals2,
        'table_missed_2' => $tableMissed2,
        'live_hscore' => normalizeInt($context['live_hscore'] ?? null),
        'live_ascore' => normalizeInt($context['live_ascore'] ?? null),
        'match_status' => (string) ($context['match_status'] ?? ''),
        'home' => (string) ($context['home'] ?? ''),
        'away' => (string) ($context['away'] ?? ''),
        'selected_team_side' => $selectedTeamSide,
        'selected_team_name' => $selectedTeamName,
        'selected_team_goals_current' => $selectedTeamSide === 'away'
            ? normalizeInt($context['live_ascore'] ?? null)
            : normalizeInt($context['live_hscore'] ?? null),
        'selected_team_target_bet' => $selectedTeamBet,
        'triggered_rule' => is_string($payload['triggered_rule'] ?? null) ? $payload['triggered_rule'] : null,
        'triggered_rule_label' => is_string($payload['triggered_rule_label'] ?? null) ? $payload['triggered_rule_label'] : null,
        'home_attack_ratio' => is_numeric($payload['home_attack_ratio'] ?? null)
            ? (float) $payload['home_attack_ratio']
            : calculateAlgorithmThreeRatio($tableGoals1, $tableGames1),
        'away_defense_ratio' => is_numeric($payload['away_defense_ratio'] ?? null)
            ? (float) $payload['away_defense_ratio']
            : calculateAlgorithmThreeRatio($tableMissed2, $tableGames2),
        'away_attack_ratio' => is_numeric($payload['away_attack_ratio'] ?? null)
            ? (float) $payload['away_attack_ratio']
            : calculateAlgorithmThreeRatio($tableGoals2, $tableGames2),
        'home_defense_ratio' => is_numeric($payload['home_defense_ratio'] ?? null)
            ? (float) $payload['home_defense_ratio']
            : calculateAlgorithmThreeRatio($tableMissed1, $tableGames1),
        'has_data' => $tableGames1 !== null
            && $tableGoals1 !== null
            && $tableMissed1 !== null
            && $tableGames2 !== null
            && $tableGoals2 !== null
            && $tableMissed2 !== null,
    ];
}

function calculateAlgorithmThreeRatio(?int $value, ?int $games): float
{
    if ($value === null || $games === null || $games <= 0) {
        return 0.0;
    }

    return ($value / 2) / $games;
}

function extractAlgorithmTwoH2hFirstHalfGoals(mixed $sgiJson): ?int
{
    if (!is_string($sgiJson) || trim($sgiJson) === '') {
        return null;
    }

    $decoded = json_decode($sgiJson, true);
    if (!is_array($decoded)) {
        return null;
    }

    $h2hList = $decoded['G'] ?? ($decoded['Q']['G'] ?? null);
    if (!is_array($h2hList)) {
        return null;
    }

    $count = 0;
    $considered = 0;
    foreach (array_slice(array_values($h2hList), 0, 5) as $h2hMatch) {
        if (!is_array($h2hMatch)) {
            continue;
        }
        $firstHalf = $h2hMatch['P'][0] ?? null;
        if (!is_array($firstHalf)) {
            continue;
        }
        if (!is_numeric($firstHalf['H'] ?? null) || !is_numeric($firstHalf['A'] ?? null)) {
            continue;
        }
        $considered++;
        if (((int) $firstHalf['H'] + (int) $firstHalf['A']) > 0) {
            $count++;
        }
    }

    return $considered === 0 ? null : $count;
}

function normalizeInt(mixed $value): int
{
    return is_numeric($value) ? (int) $value : 0;
}

function normalizeFloat(mixed $value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}

function extractMinuteFromTime(string $time): int
{
    if (preg_match('/^(\d{1,3}):\d{2}$/', trim($time), $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
}
