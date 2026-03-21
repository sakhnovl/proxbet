<?php

declare(strict_types=1);

require_once __DIR__ . '/../security/InputValidator.php';
require_once __DIR__ . '/TelegramAiRepository.php';
require_once __DIR__ . '/Commands/CommandRegistry.php';
require_once __DIR__ . '/Commands/StartCommand.php';
require_once __DIR__ . '/Commands/BalanceCommand.php';
require_once __DIR__ . '/Commands/BuyCommand.php';
require_once __DIR__ . '/Handlers/AnalysisCallbackHandler.php';

use Proxbet\Security\InputValidator;
use Proxbet\Telegram\Commands\CommandRegistry;
use Proxbet\Telegram\Commands\StartCommand;
use Proxbet\Telegram\Commands\BalanceCommand;
use Proxbet\Telegram\Commands\BuyCommand;
use Proxbet\Telegram\Handlers\AnalysisCallbackHandler;
use Proxbet\Telegram\TelegramAiRepository;

/**
 * Handle public commands using Command pattern
 *
 * @param array<string, mixed> $from
 * @param int $chatId
 * @param string $textTrim
 * @param array<string, mixed> $ctx
 * @return bool
 */
function tryHandlePublicCommand(array $from, int $chatId, string $textTrim, array $ctx): bool
{
    $telegramUserId = (int) ($from['id'] ?? 0);
    
    // Validate and sanitize command input
    $textTrim = InputValidator::sanitizeTelegramInput($textTrim, 100) ?? '';
    
    if ($telegramUserId <= 0 || $textTrim === '') {
        return false;
    }

    $repository = new TelegramAiRepository($ctx['db']);
    
    // Build command registry
    $registry = new CommandRegistry();
    $registry->register(new StartCommand($repository));
    $registry->register(new BalanceCommand($repository));
    $registry->register(new BuyCommand());
    
    // Find and execute command
    $command = $registry->findCommand($textTrim);
    if ($command === null) {
        return false;
    }
    
    $commandContext = [
        'from' => $from,
        'chat_id' => $chatId,
        'api_base' => (string) $ctx['apiBase'],
        'admin_ids' => (array) ($ctx['adminIds'] ?? []),
    ];
    
    return $command->execute($commandContext);
}

/**
 * Handle analysis callback using dedicated handler
 *
 * @param array<string, mixed> $cq
 * @param array<string, mixed> $ctx
 * @return bool
 */
function tryHandleAnalysisCallback(array $cq, array $ctx): bool
{
    $repository = new TelegramAiRepository($ctx['db']);
    $handler = new AnalysisCallbackHandler($repository);
    
    return $handler->handle($cq, $ctx);
}
