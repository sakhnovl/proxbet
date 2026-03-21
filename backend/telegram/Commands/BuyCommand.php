<?php

declare(strict_types=1);

namespace Proxbet\Telegram\Commands;

require_once __DIR__ . '/../../bans/tg_api.php';
require_once __DIR__ . '/../public_messages.php';

/**
 * Handles /buy command
 */
class BuyCommand implements CommandInterface
{
    public function getName(): string
    {
        return '/buy';
    }

    public function canHandle(string $input): bool
    {
        return $input === '/buy';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function execute(array $context): bool
    {
        $chatId = (int) ($context['chat_id'] ?? 0);
        $apiBase = (string) ($context['api_base'] ?? '');

        if ($chatId <= 0) {
            return false;
        }

        tgSendMessage($apiBase, $chatId, buildBuyMessage(), creditMessageOptions());

        return true;
    }
}
