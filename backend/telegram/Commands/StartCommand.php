<?php

declare(strict_types=1);

namespace Proxbet\Telegram\Commands;

use Proxbet\Telegram\TelegramAiRepository;

require_once __DIR__ . '/../../bans/tg_api.php';
require_once __DIR__ . '/../TelegramAiRepository.php';
require_once __DIR__ . '/../public_messages.php';

/**
 * Handles /start command
 */
class StartCommand implements CommandInterface
{
    private TelegramAiRepository $repository;

    public function __construct(TelegramAiRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getName(): string
    {
        return '/start';
    }

    public function canHandle(string $input): bool
    {
        return $input === '/start';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function execute(array $context): bool
    {
        $from = $context['from'] ?? [];
        $chatId = (int) ($context['chat_id'] ?? 0);
        $apiBase = (string) ($context['api_base'] ?? '');
        $adminIds = (array) ($context['admin_ids'] ?? []);
        $telegramUserId = (int) ($from['id'] ?? 0);

        if ($chatId <= 0 || $telegramUserId <= 0) {
            return false;
        }

        $user = $this->repository->upsertTelegramUser($from);

        tgSendMessage(
            $apiBase,
            $chatId,
            buildStartMessage($user, $adminIds, $telegramUserId),
            buildStartMessageOptions($adminIds, $telegramUserId)
        );

        return true;
    }
}
