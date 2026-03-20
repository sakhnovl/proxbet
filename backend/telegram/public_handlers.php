<?php

declare(strict_types=1);

require_once __DIR__ . '/../bans/tg_api.php';
require_once __DIR__ . '/../line/logger.php';
require_once __DIR__ . '/../scanner/ProbabilityCalculator.php';
require_once __DIR__ . '/../scanner/MatchFilter.php';
require_once __DIR__ . '/TelegramAiRepository.php';
require_once __DIR__ . '/GeminiMatchAnalyzer.php';
require_once __DIR__ . '/GeminiPoolAnalyzer.php';

use Proxbet\Line\Logger;
use Proxbet\Scanner\MatchFilter;
use Proxbet\Scanner\ProbabilityCalculator;
use Proxbet\Telegram\GeminiMatchAnalyzer;
use Proxbet\Telegram\GeminiPoolAnalyzer;
use Proxbet\Telegram\TelegramAiRepository;

function analysisCallbackData(int $matchId, int $algorithmId = 1): string
{
    return 'ai:match:' . max(0, $matchId) . ':alg:' . max(1, $algorithmId);
}

/**
 * @return array{match_id:int,algorithm_id:int}|null
 */
function parseAnalysisCallbackData(string $data): ?array
{
    if (preg_match('/^ai:match:(\d+):alg:(\d+)$/', $data, $matches) === 1) {
        return [
            'match_id' => (int) $matches[1],
            'algorithm_id' => max(1, (int) $matches[2]),
        ];
    }

    if (preg_match('/^ai:match:(\d+)$/', $data, $matches) === 1) {
        return [
            'match_id' => (int) $matches[1],
            'algorithm_id' => 1,
        ];
    }

    return null;
}

function getAnalysisButtonText(): string
{
    $text = trim((string) (getenv('TELEGRAM_AI_BUTTON_TEXT') ?: '🤖 Анализ Gemini'));
    return $text !== '' ? $text : '🤖 Анализ Gemini';
}

function getCreditsTopUpUrl(): string
{
    $raw = trim((string) (getenv('TELEGRAM_CREDITS_TOPUP_URL') ?: getenv('TELEGRAM_SUBSCRIPTION_URL') ?: ''));
    if ($raw === '') {
        return '';
    }

    if (preg_match('/^@?([A-Za-z][A-Za-z0-9_]{3,31})$/', $raw, $matches) === 1) {
        return 'https://t.me/' . $matches[1];
    }

    return $raw;
}

function getAnalysisCost(): int
{
    return max(1, (int) (getenv('AI_ANALYSIS_COST') ?: 1));
}

function tryHandlePublicCommand(array $from, int $chatId, string $textTrim, array $ctx): bool
{
    $telegramUserId = (int) ($from['id'] ?? 0);
    if ($telegramUserId <= 0) {
        return false;
    }

    if (!in_array($textTrim, ['/start', '/balance', '/buy'], true)) {
        return false;
    }

    $apiBase = (string) $ctx['apiBase'];
    $repository = new TelegramAiRepository($ctx['db']);
    $user = $repository->upsertTelegramUser($from);

    if ($textTrim === '/start') {
        tgSendMessage(
            $apiBase,
            $chatId,
            buildStartMessage($user, (array) $ctx['adminIds'], $telegramUserId),
            buildStartMessageOptions((array) $ctx['adminIds'], $telegramUserId)
        );
        return true;
    }

    if ($textTrim === '/balance') {
        tgSendMessage($apiBase, $chatId, buildBalanceMessage($user), creditMessageOptions());
        return true;
    }

    tgSendMessage($apiBase, $chatId, buildBuyMessage(), creditMessageOptions());
    return true;
}

function tryHandleAnalysisCallback(array $cq, array $ctx): bool
{
    $data = (string) ($cq['data'] ?? '');
    $parsed = parseAnalysisCallbackData($data);
    if ($parsed === null) {
        return false;
    }

    $cbId = (string) ($cq['id'] ?? '');
    $from = is_array($cq['from'] ?? null) ? $cq['from'] : [];
    $telegramUserId = (int) ($from['id'] ?? 0);
    $apiBase = (string) $ctx['apiBase'];
    $matchId = (int) $parsed['match_id'];
    $algorithmId = (int) $parsed['algorithm_id'];

    if ($cbId === '' || $telegramUserId <= 0 || $matchId <= 0) {
        return true;
    }

    $repository = new TelegramAiRepository($ctx['db']);
    $user = $repository->upsertTelegramUser($from);

    if (false) {
        if (deliverPrivateMessage($apiBase, $telegramUserId, buildAnalysisDeliveryMessage((string) ($existing['response_text'] ?? ''), $user))) {
            tgAnswerCallback($apiBase, $cbId, 'Отправил сохранённый анализ в личные сообщения.');
        } else {
            tgAnswerCallback($apiBase, $cbId, 'Откройте бота и нажмите /start, затем повторите запрос.', true);
        }
        return true;
    }

    $context = $repository->getAnalysisContext($matchId, $algorithmId);
    if ($context === null) {
        $context = buildFallbackAnalysisContextFromCallback($cq, $matchId);
    }

    if ($context === null) {
        tgAnswerCallback($apiBase, $cbId, 'Не удалось найти данные матча для анализа.', true);
        return true;
    }

    $context['algorithm_id'] = $algorithmId;
    if (!isset($context['algorithm_name']) || trim((string) ($context['algorithm_name'] ?? '')) === '') {
        $context['algorithm_name'] = 'Алгоритм ' . $algorithmId;
    }

    $existing = $repository->getAnalysisRequest(
        $telegramUserId,
        $matchId,
        isset($context['bet_message_id']) ? (int) $context['bet_message_id'] : null
    );
    if (is_array($existing) && (string) ($existing['status'] ?? '') === 'completed') {
        if (deliverPrivateMessage($apiBase, $telegramUserId, buildAnalysisDeliveryMessage((string) ($existing['response_text'] ?? ''), $user))) {
            tgAnswerCallback($apiBase, $cbId, 'РћС‚РїСЂР°РІРёР» СЃРѕС…СЂР°РЅС‘РЅРЅС‹Р№ Р°РЅР°Р»РёР· РІ Р»РёС‡РЅС‹Рµ СЃРѕРѕР±С‰РµРЅРёСЏ.');
        } else {
            tgAnswerCallback($apiBase, $cbId, 'РћС‚РєСЂРѕР№С‚Рµ Р±РѕС‚Р° Рё РЅР°Р¶РјРёС‚Рµ /start, Р·Р°С‚РµРј РїРѕРІС‚РѕСЂРёС‚Рµ Р·Р°РїСЂРѕСЃ.', true);
        }
        return true;
    }

    $context = enrichAnalysisContextWithScanner($context);

    $activeKeys = $repository->listActiveGeminiKeys();
    $activeModels = $repository->listActiveGeminiModels();
    if ($activeKeys === [] || $activeModels === []) {
        tgAnswerCallback($apiBase, $cbId, 'Gemini ещё не настроен в базе ключей и моделей.', true);
        return true;
    }

    $access = $repository->consumeAnalysisAccess($telegramUserId, getAnalysisCost());
    if (!$access['allowed']) {
        tgAnswerCallback($apiBase, $cbId, 'Недостаточно кредитов. Откройте бота и пополните баланс.', true);
        deliverPrivateMessage($apiBase, $telegramUserId, buildBuyMessage(), true);
        return true;
    }

    tgAnswerCallback($apiBase, $cbId, 'Готовлю AI-анализ и отправлю его в личные сообщения.');

    $poolAnalyzer = new GeminiPoolAnalyzer($repository);

    try {
        $analysis = $poolAnalyzer->analyze($context);
        $repository->savePendingAnalysis(
            $telegramUserId,
            $matchId,
            isset($context['bet_message_id']) ? (int) $context['bet_message_id'] : null,
            $analysis['provider'],
            $analysis['model'],
            $analysis['prompt'],
            (int) $access['charged']
        );
        $repository->saveCompletedAnalysis($telegramUserId, $matchId, $analysis['response']);

        $freshUser = $repository->getTelegramUser($telegramUserId) ?? $user;
        deliverPrivateMessage($apiBase, $telegramUserId, buildAnalysisDeliveryMessage($analysis['response'], $freshUser));
    } catch (\Throwable $e) {
        Logger::error('Gemini analysis failed', [
            'telegram_user_id' => $telegramUserId,
            'match_id' => $matchId,
            'algorithm_id' => $algorithmId,
            'error' => $e->getMessage(),
        ]);

        $repository->savePendingAnalysis(
            $telegramUserId,
            $matchId,
            isset($context['bet_message_id']) ? (int) $context['bet_message_id'] : null,
            'gemini',
            'pool-rotation',
            'fallback prompt for match ' . $matchId . ' algorithm ' . $algorithmId,
            (int) $access['charged']
        );
        $repository->saveFailedAnalysis($telegramUserId, $matchId, $e->getMessage());

        if ((int) $access['charged'] > 0) {
            $repository->refundBalance($telegramUserId, (int) $access['charged']);
        }

        deliverPrivateMessage(
            $apiBase,
            $telegramUserId,
            'Не удалось получить ответ от Gemini. Баланс возвращён, попробуйте ещё раз чуть позже.'
        );
    }

    return true;
}

/**
 * @param array<string,mixed> $user
 */
function buildStartMessage(array $user, array $adminIds, int $telegramUserId): string
{
    $text = "Добро пожаловать в <b>Клоун Бет</b>.\n";

    if ((bool) ($user['is_new_user'] ?? false)) {
        $trialCredits = (int) ($user['trial_balance_granted'] ?? 0);
        $text .= "\n🎁 <b>Приветственный бонус</b>\n"
            . "Мы начислили вам <b>{$trialCredits} кредитов</b> для теста AI-анализа.\n"
            . "Попробуйте любой матч прямо сейчас.\n";
    }

    $text .= "\n" . buildBalanceMessage($user);

    if (in_array($telegramUserId, $adminIds, true)) {
        $text .= "\n\nАдмин-команды:\n"
            . "Ниже кнопки для копирования команд.";
    }

    return $text;
}

/**
 * @param array<string,mixed> $user
 */
function buildBalanceMessage(array $user): string
{
    $balance = (int) ($user['ai_balance'] ?? 0);

    return "Ваш AI-баланс: <b>{$balance}</b>\n"
        . "Стоимость одного AI-анализа: <b>" . getAnalysisCost() . "</b>";
}

function buildBuyMessage(): string
{
    if (getCreditsTopUpUrl() !== '') {
        return "Кредиты закончились.\n"
            . "Чтобы продолжить получать AI-разборы матчей, пополните баланс по кнопке ниже.";
    }

    return "Кредиты закончились.\n"
        . "Автопополнение ещё не подключено. Свяжитесь с администратором для начисления кредитов.";
}

/**
 * @param array<string,mixed> $user
 */
function buildAnalysisDeliveryMessage(string $analysisText, array $user): string
{
    return "AI-анализ матча:\n\n"
        . $analysisText
        . "\n\n"
        . buildBalanceMessage($user);
}

/**
 * @return array<string,mixed>|null
 */
function buildFallbackAnalysisContextFromCallback(array $cq, int $matchId): ?array
{
    $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];
    $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
    if ($text === '') {
        return null;
    }

    $home = null;
    $away = null;
    if (preg_match('/⚽\s*(.+?)\s*-\s*(.+)/u', $text, $m) === 1) {
        $home = trim($m[1]);
        $away = trim($m[2]);
    }

    $liga = null;
    if (preg_match('/🏆\s*(.+)/u', $text, $m) === 1) {
        $liga = trim($m[1]);
    }

    $time = null;
    if (preg_match('/Время:\s*([0-9:]+)/u', $text, $m) === 1) {
        $time = trim($m[1]);
    }

    $scoreHome = null;
    $scoreAway = null;
    if (preg_match('/Сч[её]т:\s*([0-9]+):([0-9]+)/u', $text, $m) === 1) {
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

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
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
        'ht_hscore' => normalizeInt($context['live_ht_hscore'] ?? null),
        'ht_ascore' => normalizeInt($context['live_ht_ascore'] ?? null),
        'time_str' => (string) ($context['time'] ?? ''),
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

/**
 * @param array<string,mixed> $context
 * @return array{
 *   home_win_odd:float,
 *   over_25_odd:float|null,
 *   total_line:float|null,
 *   over_25_odd_check_skipped:bool,
 *   home_first_half_goals_in_last_5:int,
 *   h2h_first_half_goals_in_last_5:int,
 *   has_data:bool
 * }
 */
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

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
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
            $homeStrength = $homeAttackRatio + $awayDefenseRatio;
            $awayStrength = $awayAttackRatio + $homeDefenseRatio;
            if ($homeStrength >= $awayStrength) {
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

        $homeGoals = $firstHalf['H'] ?? null;
        $awayGoals = $firstHalf['A'] ?? null;
        if (!is_numeric($homeGoals) || !is_numeric($awayGoals)) {
            continue;
        }

        $considered++;
        if (((int) $homeGoals + (int) $awayGoals) > 0) {
            $count++;
        }
    }

    if ($considered === 0) {
        return null;
    }

    return $count;
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

/**
 * @return array<string,mixed>
 */
function creditMessageOptions(): array
{
    $rows = buildCreditButtonRows();
    if ($rows === []) {
        return [];
    }

    return [
        'reply_markup' => json_encode([
            'inline_keyboard' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

/**
 * @param array<int,int> $adminIds
 * @return array<string,mixed>
 */
function buildStartMessageOptions(array $adminIds, int $telegramUserId): array
{
    $rows = [];

    if (in_array($telegramUserId, $adminIds, true)) {
        $rows = array_merge($rows, buildAdminCopyCommandRows());
    }

    $rows = array_merge($rows, buildCreditButtonRows());

    if ($rows === []) {
        return [];
    }

    return [
        'reply_markup' => json_encode([
            'inline_keyboard' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

/**
 * @return array<int,array<int,array<string,mixed>>>
 */
function buildAdminCopyCommandRows(): array
{
    return [
        [copyTextButton('Скопировать /bans', '/bans')],
        [copyTextButton('Скопировать /gemini_key_list', '/gemini_key_list')],
        [copyTextButton('Скопировать /gemini_model_list', '/gemini_model_list')],
        [copyTextButton('Скопировать balance', '/grant_balance 123456789 1')],
        [copyTextButton('Скопировать key add', '/gemini_key_add YOUR_API_KEY')],
        [copyTextButton('Скопировать model add', '/gemini_model_add gemma-3-27b-it')],
        [copyTextButton('Скопировать key on', '/gemini_key_on 1')],
        [copyTextButton('Скопировать key off', '/gemini_key_off 1')],
        [copyTextButton('Скопировать model on', '/gemini_model_on 1')],
        [copyTextButton('Скопировать model off', '/gemini_model_off 1')],
    ];
}

/**
 * @return array<int,array<int,array<string,mixed>>>
 */
function buildCreditButtonRows(): array
{
    $url = getCreditsTopUpUrl();
    if ($url === '') {
        return [];
    }

    return [
        [
            ['text' => 'Пополнить кредиты', 'url' => $url],
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function copyTextButton(string $label, string $text): array
{
    return [
        'text' => $label,
        'copy_text' => [
            'text' => $text,
        ],
    ];
}

function deliverPrivateMessage(string $apiBase, int $telegramUserId, string $text, bool $withTopUpButton = false): bool
{
    try {
        tgSendMessage($apiBase, $telegramUserId, $text, $withTopUpButton ? creditMessageOptions() : []);
        return true;
    } catch (\Throwable $e) {
        Logger::error('Failed to deliver private Telegram message', [
            'telegram_user_id' => $telegramUserId,
            'error' => $e->getMessage(),
        ]);
        return false;
    }
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function formatGeminiKeysList(array $rows): string
{
    if ($rows === []) {
        return "Gemini ключи ещё не добавлены.";
    }

    $lines = ["Gemini API keys:"];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $status = ((int) ($row['is_active'] ?? 0) === 1) ? 'ON' : 'OFF';
        $masked = maskSecret((string) ($row['api_key'] ?? ''));
        $fails = (int) ($row['fail_count'] ?? 0);
        $lastError = trim((string) ($row['last_error'] ?? ''));
        $lines[] = "#{$id} [{$status}] {$masked} fails={$fails}" . ($lastError !== '' ? " err={$lastError}" : '');
    }

    return implode("\n", $lines);
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function formatGeminiModelsList(array $rows): string
{
    if ($rows === []) {
        return "Gemini модели ещё не добавлены.";
    }

    $lines = ["Gemini models:"];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $status = ((int) ($row['is_active'] ?? 0) === 1) ? 'ON' : 'OFF';
        $name = (string) ($row['model_name'] ?? '');
        $fails = (int) ($row['fail_count'] ?? 0);
        $lastError = trim((string) ($row['last_error'] ?? ''));
        $lines[] = "#{$id} [{$status}] {$name} fails={$fails}" . ($lastError !== '' ? " err={$lastError}" : '');
    }

    return implode("\n", $lines);
}

function maskSecret(string $value): string
{
    $trimmed = trim($value);
    $len = strlen($trimmed);
    if ($len <= 8) {
        return str_repeat('*', max(4, $len));
    }

    return substr($trimmed, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($trimmed, -4);
}
