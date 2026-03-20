<?php

declare(strict_types=1);

require_once __DIR__ . '/../bans/tg_api.php';
require_once __DIR__ . '/../line/logger.php';
require_once __DIR__ . '/TelegramAiRepository.php';
require_once __DIR__ . '/GeminiPoolAnalyzer.php';
require_once __DIR__ . '/public_support.php';
require_once __DIR__ . '/public_messages.php';
require_once __DIR__ . '/public_analysis.php';

use Proxbet\Line\Logger;
use Proxbet\Telegram\GeminiPoolAnalyzer;
use Proxbet\Telegram\TelegramAiRepository;

function tryHandlePublicCommand(array $from, int $chatId, string $textTrim, array $ctx): bool
{
    $telegramUserId = (int) ($from['id'] ?? 0);
    if ($telegramUserId <= 0 || !in_array($textTrim, ['/start', '/balance', '/buy'], true)) {
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
    $parsed = parseAnalysisCallbackData((string) ($cq['data'] ?? ''));
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
            tgAnswerCallback($apiBase, $cbId, 'Отправил сохраненный анализ в личные сообщения.');
        } else {
            tgAnswerCallback($apiBase, $cbId, 'Откройте бота и нажмите /start, затем повторите запрос.', true);
        }
        return true;
    }

    $context = enrichAnalysisContextWithScanner($context);
    if ($repository->listActiveGeminiKeys() === [] || $repository->listActiveGeminiModels() === []) {
        tgAnswerCallback($apiBase, $cbId, 'Gemini еще не настроен в базе ключей и моделей.', true);
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
            'Не удалось получить ответ от Gemini. Баланс возвращен, попробуйте еще раз чуть позже.'
        );
    }

    return true;
}
