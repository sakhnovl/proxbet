<?php

declare(strict_types=1);

namespace Proxbet\Telegram\Commands;

use Proxbet\Telegram\TelegramAiRepository;

require_once __DIR__ . '/../../bans/tg_api.php';
require_once __DIR__ . '/../TelegramAiRepository.php';
require_once __DIR__ . '/../public_messages.php';

/**
 * Handles /balance command
 */
class BalanceCommand implements CommandInterface
{
    private TelegramAiRepository $repository;

    public function __construct(TelegramAiRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getName(): string
    {
        return '/balance';
    }

    public function canHandle(string $input): bool
    {
        return $input === '/balance';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function execute(array $context): bool
    {
        $from = $context['from'] ?? [];
        $chatId = (int) ($context['chat_id'] ?? 0);
        $apiBase = (string) ($context['api_base'] ?? '');
        $telegramUserId = (int) ($from['id'] ?? 0);

        if ($chatId <= 0 || $telegramUserId <= 0) {
            return false;
        }

        $user = $this->repository->upsertTelegramUser($from);

        tgSendMessage($apiBase, $chatId, buildBalanceMessage($user), creditMessageOptions());

        return true;
    }
}
