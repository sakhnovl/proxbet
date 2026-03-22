<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

/**
 * Handles output formatting for scanner CLI.
 */
class ScannerOutput
{
    /**
     * Output results in JSON format.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data): void
    {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }

    /**
     * Output results in formatted text.
     *
     * @param array{
     *   total:int,
     *   analyzed:int,
     *   signals:int,
     *   results:array<int,array<string,mixed>>,
     *   algorithm_one_debug?:array{accepted:int,rejected:array<string,int>}
     * } $result
     */
    public static function formatted(array $result, bool $verbose): void
    {
        echo str_repeat('=', 80) . PHP_EOL;
        echo 'СКАНЕР LIVE-СИГНАЛОВ' . PHP_EOL;
        echo str_repeat('=', 80) . PHP_EOL;
        echo PHP_EOL;

        echo "Всего матчей: {$result['total']}" . PHP_EOL;
        echo "Проанализировано: {$result['analyzed']}" . PHP_EOL;
        echo "Сигналов на ставку: {$result['signals']}" . PHP_EOL;
        echo PHP_EOL;

        self::displayAlgorithmOneDebugSummary($result['algorithm_one_debug'] ?? null);

        if (empty($result['results'])) {
            echo 'Нет активных матчей для анализа.' . PHP_EOL;
            return;
        }

        $signals = [];
        $others = [];

        foreach ($result['results'] as $match) {
            if ($match['decision']['bet']) {
                $signals[] = $match;
            } else {
                $others[] = $match;
            }
        }

        if ($signals !== []) {
            echo str_repeat('=', 80) . PHP_EOL;
            echo 'СИГНАЛЫ НА СТАВКУ (' . $result['signals'] . ')' . PHP_EOL;
            echo str_repeat('=', 80) . PHP_EOL;
            echo PHP_EOL;

            foreach ($signals as $match) {
                self::displayMatch($match, true);
            }
        }

        if ($verbose && $others !== []) {
            echo str_repeat('=', 80) . PHP_EOL;
            echo 'ОСТАЛЬНЫЕ РЕЗУЛЬТАТЫ (' . count($others) . ')' . PHP_EOL;
            echo str_repeat('=', 80) . PHP_EOL;
            echo PHP_EOL;

            foreach ($others as $match) {
                self::displayMatch($match, false);
            }
        }
    }

    /**
     * Display a single match.
     *
     * @param array<string,mixed> $match
     */
    private static function displayMatch(array $match, bool $isSignal): void
    {
        $icon = $isSignal ? '[+]' : '[-]';
        $algorithmId = (int) ($match['algorithm_id'] ?? 1);
        $algorithmName = (string) ($match['algorithm_name'] ?? ('Алгоритм ' . $algorithmId));
        $probability = $match['probability'] !== null
            ? sprintf('%.0f%%', ((float) $match['probability']) * 100)
            : null;

        echo "{$icon} [{$match['time']}] {$match['home']} - {$match['away']}" . PHP_EOL;
        echo "   {$match['country']} / {$match['liga']}" . PHP_EOL;
        echo "   Алгоритм: {$algorithmName}" . PHP_EOL;

        if ($algorithmId === 3) {
            self::displayAlgorithmThreeMatch($match);
        } elseif ($probability !== null) {
            echo '   Вероятность: ' . $probability
                . ' (форма: ' . sprintf('%.2f', (float) $match['form_score'])
                . ', H2H: ' . sprintf('%.2f', (float) $match['h2h_score'])
                . ', live: ' . sprintf('%.2f', (float) $match['live_score']) . ')' . PHP_EOL;
        } else {
            $algorithmData = is_array($match['algorithm_data'] ?? null) ? $match['algorithm_data'] : [];
            $over25Text = !empty($algorithmData['over_25_odd_check_skipped'])
                ? 'skip, line ' . sprintf('%.2f', (float) ($algorithmData['total_line'] ?? 0))
                : sprintf('%.2f', (float) ($algorithmData['over_25_odd'] ?? 0));
            echo '   Условия A2: П1 ' . sprintf('%.2f', (float) ($algorithmData['home_win_odd'] ?? 0))
                . ', ТБ 2.5 ' . $over25Text
                . ', форма ' . (int) ($algorithmData['home_first_half_goals_in_last_5'] ?? 0) . '/5'
                . ', H2H any team ' . (int) ($algorithmData['h2h_first_half_goals_in_last_5'] ?? 0) . '/5' . PHP_EOL;
        }

        echo '   Статистика: удары ' . $match['stats']['shots_total']
            . ' (в створ ' . $match['stats']['shots_on_target'] . '), опасные атаки '
            . $match['stats']['dangerous_attacks'] . ', угловые ' . $match['stats']['corners'] . PHP_EOL;
        echo "   Форма 1T: дома {$match['form_data']['home_goals']}/5, гости {$match['form_data']['away_goals']}/5" . PHP_EOL;
        echo "   H2H 1T: дома {$match['h2h_data']['home_goals']}/5, гости {$match['h2h_data']['away_goals']}/5" . PHP_EOL;

        $algorithmData = is_array($match['algorithm_data'] ?? null) ? $match['algorithm_data'] : [];
        if ((int) ($algorithmData['algorithm_version'] ?? 0) === 2) {
            echo '   Debug A1 v2: gating=' . (($algorithmData['gating_passed'] ?? false) ? 'pass' : 'fail')
                . ', gating_reason=' . (($algorithmData['gating_reason'] ?? '') === '' ? '-' : $algorithmData['gating_reason'])
                . ', red_flag=' . (($algorithmData['red_flag'] ?? null) ?? '-') . PHP_EOL;
        }

        echo "   Решение: {$match['decision']['reason']}" . PHP_EOL;
        echo PHP_EOL;
    }

    /**
     * @param array<string,mixed> $match
     */
    private static function displayAlgorithmThreeMatch(array $match): void
    {
        $algorithmData = is_array($match['algorithm_data'] ?? null) ? $match['algorithm_data'] : [];
        $selectedTeam = (string) ($algorithmData['selected_team_name'] ?? '-');
        $targetBet = (string) ($algorithmData['selected_team_target_bet'] ?? '-');
        $triggeredRule = (string) ($algorithmData['triggered_rule'] ?? '-');

        echo '   Сигнал: ИТ команды (' . $selectedTeam . ')' . PHP_EOL;
        echo '   Ставка: ' . $targetBet . PHP_EOL;
        echo '   Статус/счет: ' . ($match['match_status'] ?? '-') . ', '
            . (int) ($match['score_home'] ?? 0) . ':' . (int) ($match['score_away'] ?? 0) . PHP_EOL;
        echo '   Таблица: дома '
            . (int) ($algorithmData['table_games_1'] ?? 0) . '/'
            . (int) ($algorithmData['table_goals_1'] ?? 0) . '/'
            . (int) ($algorithmData['table_missed_1'] ?? 0)
            . ', гости '
            . (int) ($algorithmData['table_games_2'] ?? 0) . '/'
            . (int) ($algorithmData['table_goals_2'] ?? 0) . '/'
            . (int) ($algorithmData['table_missed_2'] ?? 0) . PHP_EOL;
        echo '   Ratio: home att ' . sprintf('%.2f', (float) ($algorithmData['home_attack_ratio'] ?? 0))
            . ', away def ' . sprintf('%.2f', (float) ($algorithmData['away_defense_ratio'] ?? 0))
            . ', away att ' . sprintf('%.2f', (float) ($algorithmData['away_attack_ratio'] ?? 0))
            . ', home def ' . sprintf('%.2f', (float) ($algorithmData['home_defense_ratio'] ?? 0)) . PHP_EOL;
        echo '   Правило: ' . $triggeredRule . PHP_EOL;
    }

    /**
     * @param array{accepted:int,rejected:array<string,int>}|null $summary
     */
    private static function displayAlgorithmOneDebugSummary(?array $summary): void
    {
        if ($summary === null) {
            return;
        }

        echo 'Algorithm 1 debug:' . PHP_EOL;
        echo '  accepted: ' . $summary['accepted'] . PHP_EOL;

        $rejected = $summary['rejected'];
        if ($rejected === []) {
            echo '  rejected: 0' . PHP_EOL;
            echo PHP_EOL;
            return;
        }

        echo '  rejected reasons:' . PHP_EOL;
        foreach ($rejected as $reason => $count) {
            echo '    - ' . $reason . ': ' . $count . PHP_EOL;
        }
        echo PHP_EOL;
    }
}
