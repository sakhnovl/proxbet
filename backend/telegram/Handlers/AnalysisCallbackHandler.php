<?php

declare(strict_types=1);

namespace Proxbet\Telegram\Handlers;

use Proxbet\Line\Logger;
use Proxbet\Security\InputValidator;
use Proxbet\Telegram\GeminiPoolAnalyzer;
use Proxbet\Telegram\TelegramAiRepository;

require_once __DIR__ . '/../../bans/tg_api.php';
require_once __DIR__ . '/../../line/logger.php';
require_once __DIR__ . '/../../security/InputValidator.php';
require_once __DIR__ . '/../TelegramAiRepository.php';
require_once __DIR__ . '/../GeminiPoolAnalyzer.php';
require_once __DIR__ . '/../public_support.php';
require_once __DIR__ . '/../public_messages.php';
require_once __DIR__ . '/../public_analysis.php';

/**
 * Handles analysis callback queries from Telegram
 */
class AnalysisCallbackHandler
{
    private TelegramAiRepository $repository;

    public function __construct(TelegramAiRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Handle analysis callback query
     *
     * @param array<string, mixed> $cq Callback query data
     * @param array<string, mixed> $ctx Context
     * @return bool True if handled
     */
    public function handle(array $cq, array $ctx): bool
    {
        $callbackData = $this->validateCallbackData($cq);
        if ($callbackData === null) {
            return false;
        }

        $parsed = parseAnalysisCallbackData($callbackData);
        if ($parsed === null) {
            return false;
        }

        $cbId = (string) ($cq['id'] ?? '');
        $from = is_array($cq['from'] ?? null) ? $cq['from'] : [];
        $telegramUserId = (int) ($from['id'] ?? 0);
        $apiBase = (string) $ctx['apiBase'];

        $matchId = InputValidator::validateInt($parsed['match_id'] ?? 0, 1, PHP_INT_MAX) ?? 0;
        $algorithmId = InputValidator::validateInt($parsed['algorithm_id'] ?? 0, 1, PHP_INT_MAX) ?? 0;

        if ($cbId === '' || $telegramUserId <= 0 || $matchId <= 0) {
            return true;
        }

        $user = $this->repository->upsertTelegramUser($from);
        $context = $this->getAnalysisContext($cq, $matchId, $algorithmId);

        if ($context === null) {
            tgAnswerCallback($apiBase, $cbId, 'Не удалось найти данные матча для анализа.', true);
            return true;
        }

        return $this->processAnalysisRequest($cbId, $telegramUserId, $matchId, $context, $user, $apiBase);
    }

    /**
     * @param array<string, mixed> $cq
     * @return string|null
     */
    private function validateCallbackData(array $cq): ?string
    {
        return InputValidator::sanitizeTelegramInput((string) ($cq['data'] ?? ''), 256);
    }

    /**
     * @param array<string, mixed> $cq
     * @param int $matchId
     * @param int $algorithmId
     * @return array<string, mixed>|null
     */
    private function getAnalysisContext(array $cq, int $matchId, int $algorithmId): ?array
    {
        $context = $this->repository->getAnalysisContext($matchId, $algorithmId);
        if ($context === null) {
            $context = buildFallbackAnalysisContextFromCallback($cq, $matchId);
        }

        if ($context === null) {
            return null;
        }

        $context['algorithm_id'] = $algorithmId;
        if (!isset($context['algorithm_name']) || trim((string) ($context['algorithm_name'] ?? '')) === '') {
            $context['algorithm_name'] = 'Алгоритм ' . $algorithmId;
        }

        return enrichAnalysisContextWithScanner($context);
    }

    /**
     * @param string $cbId
     * @param int $telegramUserId
     * @param int $matchId
     * @param array<string, mixed> $context
     * @param array<string, mixed> $user
     * @param string $apiBase
     * @return bool
     */
    private function processAnalysisRequest(
        string $cbId,
        int $telegramUserId,
        int $matchId,
        array $context,
        array $user,
        string $apiBase
    ): bool {
        $existing = $this->repository->getAnalysisRequest(
            $telegramUserId,
            $matchId,
            isset($context['bet_message_id']) ? (int) $context['bet_message_id'] : null
        );

        if ($this->handleExistingAnalysis($existing, $cbId, $telegramUserId, $user, $apiBase)) {
            return true;
        }

        if (!$this->validateGeminiSetup($cbId, $apiBase)) {
            return true;
        }

        $access = $this->repository->consumeAnalysisAccess($telegramUserId, getAnalysisCost());
        if (!$this->handleAccessCheck($access, $cbId, $telegramUserId, $apiBase)) {
            return true;
        }

        tgAnswerCallback($apiBase, $cbId, 'Готовлю AI-анализ и отправлю его в личные сообщения.');

        $this->performAnalysis($telegramUserId, $matchId, $context, $access, $user, $apiBase);

        return true;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param string $cbId
     * @param int $telegramUserId
     * @param array<string, mixed> $user
     * @param string $apiBase
     * @return bool
     */
    private function handleExistingAnalysis(
        ?array $existing,
        string $cbId,
        int $telegramUserId,
        array $user,
        string $apiBase
    ): bool {
        if (is_array($existing) && (string) ($existing['status'] ?? '') === 'completed') {
            if (deliverPrivateMessage($apiBase, $telegramUserId, buildAnalysisDeliveryMessage((string) ($existing['response_text'] ?? ''), $user), true)) {
                tgAnswerCallback($apiBase, $cbId, 'Отправил сохраненный анализ в личные сообщения.');
            } else {
                tgAnswerCallback($apiBase, $cbId, 'Откройте бота и нажмите /start, затем повторите запрос.', true);
            }
            return true;
        }

        return false;
    }

    private function validateGeminiSetup(string $cbId, string $apiBase): bool
    {
        if ($this->repository->listActiveGeminiKeys() === [] || $this->repository->listActiveGeminiModels() === []) {
            tgAnswerCallback($apiBase, $cbId, 'Gemini еще не настроен в базе ключей и моделей.', true);
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $access
     * @param string $cbId
     * @param int $telegramUserId
     * @param string $apiBase
     * @return bool
     */
    private function handleAccessCheck(array $access, string $cbId, int $telegramUserId, string $apiBase): bool
    {
        if (!$access['allowed']) {
            tgAnswerCallback($apiBase, $cbId, 'Недостаточно кредитов. Откройте бота и пополните баланс.', true);
            deliverPrivateMessage($apiBase, $telegramUserId, buildBuyMessage(), true);
            return false;
        }

        return true;
    }

    /**
     * @param int $telegramUserId
     * @param int $matchId
     * @param array<string, mixed> $context
     * @param array<string, mixed> $access
     * @param array<string, mixed> $user
     * @param string $apiBase
     */
    private function performAnalysis(
        int $telegramUserId,
        int $matchId,
        array $context,
        array $access,
        array $user,
        string $apiBase
    ): void {
        $poolAnalyzer = new GeminiPoolAnalyzer($this->repository);
        try {
            $analysis = $poolAnalyzer->analyze($context);
            $this->saveSuccessfulAnalysis($telegramUserId, $matchId, $context, $access, $analysis);

            $freshUser = $this->repository->getTelegramUser($telegramUserId) ?? $user;
            deliverPrivateMessage($apiBase, $telegramUserId, buildAnalysisDeliveryMessage($analysis['response'], $freshUser), true);
        } catch (\Throwable $e) {
            $this->handleAnalysisFailure($e, $telegramUserId, $matchId, $context, $access, $apiBase);
        }
    }

    /**
     * @param int $telegramUserId
     * @param int $matchId
     * @param array<string, mixed> $context
     * @param array<string, mixed> $access
     * @param array<string, mixed> $analysis
     */
    private function saveSuccessfulAnalysis(
        int $telegramUserId,
        int $matchId,
        array $context,
        array $access,
        array $analysis
    ): void {
        $this->repository->savePendingAnalysis(
            $telegramUserId,
            $matchId,
            isset($context['bet_message_id']) ? (int) $context['bet_message_id'] : null,
            $analysis['provider'],
            $analysis['model'],
            $analysis['prompt'],
            (int) $access['charged']
        );
        $this->repository->saveCompletedAnalysis($telegramUserId, $matchId, $analysis['response']);
    }

    /**
     * @param \Throwable $e
     * @param int $telegramUserId
     * @param int $matchId
     * @param array<string, mixed> $context
     * @param array<string, mixed> $access
     * @param string $apiBase
     */
    private function handleAnalysisFailure(
        \Throwable $e,
        int $telegramUserId,
        int $matchId,
        array $context,
        array $access,
        string $apiBase
    ): void {
        Logger::error('Gemini analysis failed', [
            'telegram_user_id' => $telegramUserId,
            'match_id' => $matchId,
            'algorithm_id' => $context['algorithm_id'] ?? 0,
            'error' => $e->getMessage(),
        ]);

        $this->repository->savePendingAnalysis(
            $telegramUserId,
            $matchId,
            isset($context['bet_message_id']) ? (int) $context['bet_message_id'] : null,
            'gemini',
            'pool-rotation',
            'fallback prompt for match ' . $matchId . ' algorithm ' . ($context['algorithm_id'] ?? 0),
            (int) $access['charged']
        );
        $this->repository->saveFailedAnalysis($telegramUserId, $matchId, $e->getMessage());

        if ((int) $access['charged'] > 0) {
            $this->repository->refundBalance($telegramUserId, (int) $access['charged']);
        }

        deliverPrivateMessage(
            $apiBase,
            $telegramUserId,
            'Не удалось получить ответ от Gemini. Баланс возвращен, попробуйте еще раз чуть позже.'
        );
    }
}
