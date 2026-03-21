<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';

require_once __DIR__ . '/bans/tg_api.php';
require_once __DIR__ . '/telegram/public_handlers.php';
require_once __DIR__ . '/bans/state.php';
require_once __DIR__ . '/bans/auth.php';
require_once __DIR__ . '/bans/validation.php';
require_once __DIR__ . '/bans/constants.php';
require_once __DIR__ . '/bans/ui.php';
require_once __DIR__ . '/bans/context.php';
require_once __DIR__ . '/bans/handlers_message.php';
require_once __DIR__ . '/bans/handlers_callback.php';
require_once __DIR__ . '/bans/router.php';

use Proxbet\Core\GracefulShutdown;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;

proxbet_bootstrap_env();
Logger::init();
GracefulShutdown::register();

try {
    proxbet_require_env(['TELEGRAM_BOT_TOKEN', 'TELEGRAM_ADMIN_IDS', 'DB_HOST', 'DB_USER', 'DB_NAME']);

    $token = (string) getenv('TELEGRAM_BOT_TOKEN');
    $adminIdsRaw = (string) getenv('TELEGRAM_ADMIN_IDS');
    $adminIds = array_values(array_filter(array_map(
        static fn($x) => (int) trim($x),
        explode(',', $adminIdsRaw)
    ), static fn($x) => $x > 0));

    if ($adminIds === []) {
        throw new RuntimeException('TELEGRAM_ADMIN_IDS parsed empty (check format like 123,456)');
    }

    $apiBase = 'https://api.telegram.org/bot' . $token;
    $pollTimeout = max(5, min(50, (int) (getenv('TELEGRAM_POLL_TIMEOUT') ?: 25)));
    $statePath = getenv('TELEGRAM_BOT_STATE_PATH') ?: (proxbet_root_dir() . '/data/telegram_state.json');
    $stateDir = dirname($statePath);
    if (!is_dir($stateDir)) {
        @mkdir($stateDir, 0777, true);
    }

    Logger::info('Telegram bot started (long polling)', [
        'poll_timeout' => $pollTimeout,
        'admin_ids' => $adminIds,
    ]);

    $state = loadState($statePath);
    $offset = (int) ($state['last_update_id'] ?? 0);
    $db = Db::connectFromEnv();
} catch (Throwable $e) {
    Logger::error('Telegram bot bootstrap failed', ['error' => $e->getMessage()]);
    exit(1);
}

while (!GracefulShutdown::isShutdownRequested()) {
    try {
        $resp = tgRequest($apiBase, 'getUpdates', [
            'offset' => $offset > 0 ? ($offset + 1) : 0,
            'timeout' => $pollTimeout,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        if (!($resp['ok'] ?? false)) {
            throw new RuntimeException('Telegram API ok=false');
        }

        $updates = $resp['result'] ?? [];
        if (!is_array($updates)) {
            $updates = [];
        }

        $ctx = makeBotContext($apiBase, $adminIds, $statePath, $db, $state);

        foreach ($updates as $u) {
            if (!is_array($u) || !isset($u['update_id'])) {
                continue;
            }

            $offset = (int) $u['update_id'];
            $state['last_update_id'] = $offset;

            processUpdate($u, $ctx);
        }

        saveState($statePath, $state);
    } catch (Throwable $e) {
        Logger::error('Telegram polling error', ['error' => $e->getMessage()]);
        
        if (GracefulShutdown::isShutdownRequested()) {
            break;
        }
        
        // Small backoff to avoid tight loop on errors
        usleep(500 * 1000);
    }
}

// Cleanup on shutdown
GracefulShutdown::cleanup();
Logger::info('Telegram bot stopped gracefully');
exit(0);
