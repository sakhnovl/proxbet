<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Line\Logger;

require_once __DIR__ . '/../bans/tg_api.php';
require_once __DIR__ . '/../telegram/public_handlers.php';

/**
 * Sends scanner signals to Telegram with duplicate prevention.
 */
final class TelegramNotifier
{
    private string $apiBase;
    private string $channelId;
    private string $statePath;
    /** @var array<string,bool> */
    private array $sentMatches = [];
    private ?BetMessageRepository $repository;
    private ?AlgorithmOneChannelVerdictGenerator $algorithmOneVerdictGenerator;

    public function __construct(
        string $token,
        string $channelId,
        string $statePath,
        ?BetMessageRepository $repository = null,
        ?AlgorithmOneChannelVerdictGenerator $algorithmOneVerdictGenerator = null
    )
    {
        $this->apiBase = 'https://api.telegram.org/bot' . $token;
        $this->channelId = $channelId;
        $this->statePath = $statePath;
        $this->repository = $repository;
        $this->algorithmOneVerdictGenerator = $algorithmOneVerdictGenerator;
        $this->loadSentMatches();
    }

    /**
     * @param array<string,mixed> $match
     */
    public function notifySignal(array $match): void
    {
        $matchId = (int) ($match['match_id'] ?? 0);
        $algorithmId = (int) ($match['algorithm_id'] ?? 1);
        $key = $this->getMatchKey($match);

        if (isset($this->sentMatches[$key])) {
            Logger::info('Scanner notification skipped (already sent)', [
                'match_id' => $matchId,
                'algorithm_id' => $algorithmId,
                'key' => $key,
            ]);
            return;
        }

        $aiVerdict = $this->buildAlgorithmOneAiVerdict($match);
        $message = $this->formatMessage($match, $aiVerdict);

        $sent = false;
        $messageId = null;
        try {
            $resp = tgRequest($this->apiBase, 'sendMessage', [
                'chat_id' => $this->channelId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => getAnalysisButtonText(),
                                'callback_data' => analysisCallbackData($matchId, $algorithmId),
                            ],
                        ],
                    ],
                ],
            ]);

            if ($resp['ok'] ?? false) {
                $sent = true;
                $messageId = (int) ($resp['result']['message_id'] ?? 0);
                Logger::info('Scanner notification sent to channel', [
                    'match_id' => $matchId,
                    'algorithm_id' => $algorithmId,
                    'channel_id' => $this->channelId,
                    'message_id' => $messageId,
                ]);
            } else {
                Logger::error('Scanner notification failed', [
                    'match_id' => $matchId,
                    'algorithm_id' => $algorithmId,
                    'channel_id' => $this->channelId,
                    'response' => $resp,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::error('Scanner notification exception', [
                'match_id' => $matchId,
                'algorithm_id' => $algorithmId,
                'channel_id' => $this->channelId,
                'error' => $e->getMessage(),
            ]);
        }

        if ($sent) {
            $this->sentMatches[$key] = true;
            $this->saveSentMatches();

            if ($this->repository !== null && $messageId > 0) {
                try {
                    $this->repository->saveBetMessage(
                        $matchId,
                        $messageId,
                        $this->channelId,
                        $message,
                        $algorithmId,
                        (string) ($match['algorithm_name'] ?? ('Алгоритм ' . $algorithmId)),
                        $this->buildAlgorithmPayload($match)
                    );
                } catch (\Throwable $e) {
                    Logger::error('Failed to save bet message to database', [
                        'match_id' => $matchId,
                        'message_id' => $messageId,
                        'algorithm_id' => $algorithmId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $match
     */
    private function getMatchKey(array $match): string
    {
        $matchId = (int) ($match['match_id'] ?? 0);
        $algorithmId = (int) ($match['algorithm_id'] ?? 1);

        return sprintf('match_%d_algorithm_%d', $matchId, $algorithmId);
    }

    /**
     * @param array<string,mixed> $match
     */
    private function formatMessage(array $match, ?string $aiVerdict = null): string
    {
        $algorithmId = (int) ($match['algorithm_id'] ?? 1);

        if ($algorithmId === 2) {
            return $this->formatAlgorithmTwoMessage($match);
        }

        if ($algorithmId === 3) {
            return $this->formatAlgorithmThreeMessage($match);
        }

        return $this->formatAlgorithmOneMessage($match, $aiVerdict);
    }

    /**
     * @param array<string,mixed> $match
     */
    private function formatAlgorithmOneMessage(array $match, ?string $aiVerdict = null): string
    {
        $algorithmData = is_array($match['algorithm_data'] ?? null) ? $match['algorithm_data'] : null;
        $isV2 = $algorithmData !== null && isset($algorithmData['algorithm_version']) && $algorithmData['algorithm_version'] === 2;

        if ($isV2) {
            return $this->formatAlgorithmOneV2Message($match, $algorithmData, $aiVerdict);
        }

        return $this->formatAlgorithmOneLegacyMessage($match, $aiVerdict);
    }

    /**
     * @param array<string,mixed> $match
     */
    private function formatAlgorithmOneLegacyMessage(array $match, ?string $aiVerdict = null): string
    {
        $header = $this->buildHeader($match, '🔥 <b>СИГНАЛ: ГОЛ В ПЕРВОМ ТАЙМЕ</b>');
        $statsBlock = $this->buildStatsBlock($match);
        $formBlock = $this->buildFormAndH2hBlock($match);

        $probability = sprintf('%.0f%%', ((float) ($match['probability'] ?? 0)) * 100);
        $formScore = sprintf('%.2f', (float) ($match['form_score'] ?? 0));
        $h2hScore = sprintf('%.2f', (float) ($match['h2h_score'] ?? 0));
        $liveScore = sprintf('%.2f', (float) ($match['live_score'] ?? 0));

        return $header . $this->renderAiVerdictBlock($aiVerdict)
            . "📊 <b>Вероятность: {$probability}</b>\n"
            . "├ Форма: {$formScore} (35%)\n"
            . "├ H2H: {$h2hScore} (15%)\n"
            . "└ Live: {$liveScore} (50%)\n\n"
            . $statsBlock
            . $formBlock;
    }

    /**
     * @param array<string,mixed> $match
     * @param array<string,mixed> $algorithmData
     */
    private function formatAlgorithmOneV2Message(array $match, array $algorithmData, ?string $aiVerdict = null): string
    {
        $header = $this->buildHeader($match, '🔥 <b>СИГНАЛ: ГОЛ В ПЕРВОМ ТАЙМЕ</b>');
        
        $statsBlock = $this->buildStatsBlock($match);
        $formBlock = $this->buildFormAndH2hBlock($match);

        $probability = sprintf('%.0f%%', ((float) ($match['probability'] ?? 0)) * 100);
        $formScore = sprintf('%.2f', (float) ($match['form_score'] ?? 0));
        $h2hScore = sprintf('%.2f', (float) ($match['h2h_score'] ?? 0));
        $liveScore = sprintf('%.2f', (float) ($match['live_score'] ?? 0));

        $components = is_array($algorithmData['components'] ?? null) ? $algorithmData['components'] : [];
        
        $message = $header . $this->renderAiVerdictBlock($aiVerdict)
            . "📊 <b>Вероятность: {$probability}</b>\n"
            . "├ Форма: {$formScore} (25%)\n"
            . "├ H2H: {$h2hScore} (10%)\n"
            . "└ Live: {$liveScore} (65%)\n\n";

        // Add v2 components breakdown
        if (!empty($components)) {
            $message .= "🔬 \n";
            
            if (isset($components['pdi'])) {
                $pdi = sprintf('%.2f', (float) $components['pdi']);
                $message .= "├ PDI (баланс давления): {$pdi}\n";
            }
            
            if (isset($components['shot_quality'])) {
                $shotQuality = sprintf('%.2f', (float) $components['shot_quality']);
                $message .= "├ Качество ударов: {$shotQuality}\n";
            }
            
            if (isset($components['trend_acceleration'])) {
                $trendAccel = sprintf('%.2f', (float) $components['trend_acceleration']);
                $message .= "├ Ускорение трендов: {$trendAccel}\n";
            }
            
            if (isset($components['xg_pressure'])) {
                $xgPressure = sprintf('%.2f', (float) $components['xg_pressure']);
                $message .= "├ xG давление: {$xgPressure}\n";
            }
            
            if (isset($components['time_pressure'])) {
                $timePressure = sprintf('%.2f', (float) $components['time_pressure']);
                $message .= "├ Временное давление: {$timePressure}\n";
            }
            
            if (isset($components['league_factor'])) {
                $leagueFactor = sprintf('%.2f', (float) $components['league_factor']);
                $message .= "├ Фактор лиги: {$leagueFactor}\n";
            }
            
            if (isset($components['card_factor'])) {
                $cardFactor = sprintf('%.2f', (float) $components['card_factor']);
                $message .= "└ Фактор карточек: {$cardFactor}\n";
            }
            
            $message .= "\n";
            
            // Red flag warning if present
            $redFlag = $components['red_flag'] ?? null;
            if ($redFlag !== null && $redFlag !== '') {
                $redFlagText = match($redFlag) {
                    'low_accuracy' => '⚠️ Низкая точность ударов',
                    'ineffective_pressure' => '⚠️ Неэффективное давление',
                    'xg_mismatch' => '✅ xG несоответствие (усилитель)',
                    default => "⚠️ {$redFlag}"
                };
                $message .= "{$redFlagText}\n\n";
            }
        }

        $message .= $statsBlock . $formBlock;

        return $message;
    }

    /**
     * @param array<string,mixed> $match
     */
    private function formatAlgorithmTwoMessage(array $match): string
    {
        $header = $this->buildHeader($match, '🔥 <b>СИГНАЛ: ГОЛ В ПЕРВОМ ТАЙМЕ</b>');
        $statsBlock = $this->buildStatsBlock($match);
        $formBlock = $this->buildFormAndH2hBlock($match);

        $algorithmData = is_array($match['algorithm_data'] ?? null) ? $match['algorithm_data'] : [];
        $totalLine = isset($algorithmData['total_line']) && $algorithmData['total_line'] !== null
            ? sprintf('%.2f', (float) $algorithmData['total_line'])
            : '-';
        $over25Odd = isset($algorithmData['over_25_odd']) && $algorithmData['over_25_odd'] !== null
            ? sprintf('%.2f', (float) $algorithmData['over_25_odd'])
            : '-';
        $over25Text = !empty($algorithmData['over_25_odd_check_skipped'])
            ? "линия {$totalLine} > 2.5, проверка пропущена"
            : $over25Odd;
        $homeFirstHalfGoals = (int) ($algorithmData['home_first_half_goals_in_last_5'] ?? 0);
        $h2hFirstHalfGoals = (int) ($algorithmData['h2h_first_half_goals_in_last_5'] ?? 0);

        return $header
            . "📌 <b>Условия алгоритма 2</b>\n"
            . "├ ТБ 2.5: {$over25Text}\n"
            . "├ Хозяева забивали в 1Т: {$homeFirstHalfGoals}/5\n"
            . "└ H2H с голом любой команды в 1Т: {$h2hFirstHalfGoals}/5\n\n"
            . $statsBlock
            . $formBlock;
    }

    /**
     * @param array<string,mixed> $match
     */
    private function formatAlgorithmThreeMessage(array $match): string
    {
        $header = $this->buildHeader($match, '🎯 <b>СИГНАЛ: ИНДИВИДУАЛЬНЫЙ ТОТАЛ КОМАНДЫ</b>');
        $algorithmData = is_array($match['algorithm_data'] ?? null) ? $match['algorithm_data'] : [];

        $selectedTeam = htmlspecialchars((string) ($algorithmData['selected_team_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $targetBet = htmlspecialchars((string) ($algorithmData['selected_team_target_bet'] ?? 'ИТБ больше 0.5'), ENT_QUOTES, 'UTF-8');
        $triggeredRule = htmlspecialchars(
            (string) ($algorithmData['triggered_rule_label'] ?? $algorithmData['triggered_rule'] ?? '-'),
            ENT_QUOTES,
            'UTF-8'
        );
        $reason = htmlspecialchars((string) ($match['decision']['reason'] ?? ''), ENT_QUOTES, 'UTF-8');
        $matchStatus = htmlspecialchars((string) ($match['match_status'] ?? ''), ENT_QUOTES, 'UTF-8');

        $homeGames = (int) ($algorithmData['table_games_1'] ?? 0);
        $homeGoals = (int) ($algorithmData['table_goals_1'] ?? 0);
        $homeMissed = (int) ($algorithmData['table_missed_1'] ?? 0);
        $awayGames = (int) ($algorithmData['table_games_2'] ?? 0);
        $awayGoals = (int) ($algorithmData['table_goals_2'] ?? 0);
        $awayMissed = (int) ($algorithmData['table_missed_2'] ?? 0);
        $homeAttackRatio = sprintf('%.2f', (float) ($algorithmData['home_attack_ratio'] ?? 0));
        $awayDefenseRatio = sprintf('%.2f', (float) ($algorithmData['away_defense_ratio'] ?? 0));
        $awayAttackRatio = sprintf('%.2f', (float) ($algorithmData['away_attack_ratio'] ?? 0));
        $homeDefenseRatio = sprintf('%.2f', (float) ($algorithmData['home_defense_ratio'] ?? 0));

        $home = htmlspecialchars((string) ($match['home'] ?? ''), ENT_QUOTES, 'UTF-8');
        $away = htmlspecialchars((string) ($match['away'] ?? ''), ENT_QUOTES, 'UTF-8');

        return $header
            . "⏸ Статус: <b>{$matchStatus}</b>\n"
            . "🎯 Команда: <b>{$selectedTeam}</b>\n"
            . "💰 Ставка: <b>{$targetBet}</b>\n"
            . "🧮 Сработавшее правило: <b>{$triggeredRule}</b>\n\n"
            . "📋 <b>Табличные показатели</b>\n"
            . "├ {$home}: игры {$homeGames}, забито {$homeGoals}, пропущено {$homeMissed}\n"
            . "├ коэффициенты: атака {$homeAttackRatio}, оборона {$homeDefenseRatio}\n"
            . "├ {$away}: игры {$awayGames}, забито {$awayGoals}, пропущено {$awayMissed}\n"
            . "└ коэффициенты: атака {$awayAttackRatio}, оборона {$awayDefenseRatio}\n\n"
            . "🤖 <b>Для AI</b>: оцени именно ставку <b>{$targetBet}</b> по выбранной команде <b>{$selectedTeam}</b>.\n"
            . "📝 <b>Причина сигнала</b>: {$reason}";
    }

    /**
     * @param array<string,mixed> $match
     */
    private function buildHeader(array $match, string $title): string
    {
        $algorithmId = (int) ($match['algorithm_id'] ?? 1);
        $algorithmName = htmlspecialchars($this->resolveAlgorithmName($match, $algorithmId), ENT_QUOTES, 'UTF-8');
        $home = htmlspecialchars((string) ($match['home'] ?? ''), ENT_QUOTES, 'UTF-8');
        $away = htmlspecialchars((string) ($match['away'] ?? ''), ENT_QUOTES, 'UTF-8');
        $liga = htmlspecialchars((string) ($match['liga'] ?? ''), ENT_QUOTES, 'UTF-8');
        $time = htmlspecialchars((string) ($match['time'] ?? ''), ENT_QUOTES, 'UTF-8');
        $scoreHome = (int) ($match['score_home'] ?? 0);
        $scoreAway = (int) ($match['score_away'] ?? 0);
        $score = sprintf('%d:%d', $scoreHome, $scoreAway);

        return $title . "\n"
            . "🧠 <b>{$algorithmName}</b>\n\n"
            . "⚽ <b>{$home} - {$away}</b>\n"
            . "🏆 {$liga}\n"
            . "⏱ Время: <b>{$time}</b>\n"
            . "⚽ Счет: <b>{$score}</b>\n\n";
    }

    /**
     * @param array<string,mixed> $match
     */
    private function resolveAlgorithmName(array $match, int $algorithmId): string
    {
        $baseName = (string) ($match['algorithm_name'] ?? ('Алгоритм ' . $algorithmId));
        $algorithmData = $match['algorithm_data'] ?? null;
        if (!is_array($algorithmData)) {
            return $baseName;
        }

        $version = $algorithmData['algorithm_version'] ?? null;
        if ($algorithmId !== 1 || !is_numeric($version) || (int) $version <= 1) {
            return $baseName;
        }

        $suffix = ' v' . (int) $version;
        if (stripos($baseName, $suffix) !== false) {
            return $baseName;
        }

        return $baseName . $suffix;
    }

    /**
     * @param array<string,mixed> $match
     */
    private function buildStatsBlock(array $match): string
    {
        $stats = $match['stats'] ?? [];
        $shotsTotal = (int) ($stats['shots_total'] ?? 0);
        $shotsOnTarget = (int) ($stats['shots_on_target'] ?? 0);
        $dangerAttacks = (int) ($stats['dangerous_attacks'] ?? 0);
        $corners = (int) ($stats['corners'] ?? 0);
        return "📈 <b>Статистика матча</b>\n"
            . "├ Удары: {$shotsTotal} (в створ: {$shotsOnTarget})\n"
            . "├ Опасные атаки: {$dangerAttacks}\n"
            . "└ Угловые: {$corners}\n\n";
    }

    /**
     * @param array<string,mixed> $match
     */
    private function buildFormAndH2hBlock(array $match): string
    {
        $formData = $match['form_data'] ?? [];
        $h2hData = $match['h2h_data'] ?? [];
        $homeFormGoals = (int) ($formData['home_goals'] ?? 0);
        $awayFormGoals = (int) ($formData['away_goals'] ?? 0);
        $homeH2hGoals = (int) ($h2hData['home_goals'] ?? 0);
        $awayH2hGoals = (int) ($h2hData['away_goals'] ?? 0);

        return "📋 <b>Форма</b>: дома {$homeFormGoals}/5, гости {$awayFormGoals}/5\n"
            . "🤝 <b>H2H</b>: дома {$homeH2hGoals}/5, гости {$awayH2hGoals}/5\n";
    }

    private function renderAiVerdictBlock(?string $aiVerdict): string
    {
        $aiVerdict = trim((string) $aiVerdict);
        if ($aiVerdict === '') {
            return '';
        }

        $text = htmlspecialchars($aiVerdict, ENT_QUOTES, 'UTF-8');

        return "──────────────\n"
            . "🤖 <b>AI:</b> {$text}\n"
            . "──────────────\n\n";
    }

    private function buildAlgorithmOneAiVerdict(array $match): ?string
    {
        if ($this->algorithmOneVerdictGenerator === null) {
            return null;
        }

        try {
            return $this->algorithmOneVerdictGenerator->generate($match);
        } catch (\Throwable $e) {
            Logger::info('Failed to build AlgorithmOne AI verdict for channel message', [
                'match_id' => (int) ($match['match_id'] ?? 0),
                'algorithm_id' => (int) ($match['algorithm_id'] ?? 1),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildAlgorithmPayload(array $match): ?array
    {
        $algorithmData = $match['algorithm_data'] ?? null;
        return is_array($algorithmData) ? $algorithmData : null;
    }

    private function loadSentMatches(): void
    {
        if (!file_exists($this->statePath)) {
            $this->sentMatches = [];
            return;
        }

        $content = file_get_contents($this->statePath);
        if ($content === false) {
            $this->sentMatches = [];
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['sent_matches']) || !is_array($data['sent_matches'])) {
            $this->sentMatches = [];
            return;
        }

        $this->sentMatches = $this->normalizeSentMatches($data['sent_matches']);
        $this->cleanOldEntries();
    }

    private function saveSentMatches(): void
    {
        $data = ['sent_matches' => $this->sentMatches];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            Logger::error('Failed to encode scanner state');
            return;
        }

        $result = file_put_contents($this->statePath, $json);
        if ($result === false) {
            Logger::error('Failed to save scanner state', ['path' => $this->statePath]);
        }
    }

    private function cleanOldEntries(): void
    {
        if (count($this->sentMatches) > 1000) {
            $this->sentMatches = array_slice($this->sentMatches, -500, null, true);
        }
    }

    /**
     * @param array<string,mixed> $sentMatches
     * @return array<string,bool>
     */
    private function normalizeSentMatches(array $sentMatches): array
    {
        $normalized = [];

        foreach ($sentMatches as $key => $value) {
            if (!$value || !is_string($key)) {
                continue;
            }

            if (preg_match('/^match_(\d+)_algorithm_(\d+)$/', $key, $matches) === 1) {
                $normalized['match_' . $matches[1] . '_algorithm_' . $matches[2]] = true;
                continue;
            }

            if (preg_match('/^match_(\d+)(?:_min_\d+)?$/', $key, $matches) === 1) {
                $normalized['match_' . $matches[1] . '_algorithm_1'] = true;
                continue;
            }

            $normalized[$key] = true;
        }

        return $normalized;
    }
}
