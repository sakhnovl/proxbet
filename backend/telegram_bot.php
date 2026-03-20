<?php

declare(strict_types=1);

require_once __DIR__ . '/line/env.php';
require_once __DIR__ . '/line/logger.php';
require_once __DIR__ . '/line/db.php';

require_once __DIR__ . '/bans/tg_api.php';
require_once __DIR__ . '/bans/state.php';
require_once __DIR__ . '/bans/auth.php';
require_once __DIR__ . '/bans/validation.php';
require_once __DIR__ . '/bans/constants.php';
require_once __DIR__ . '/bans/ui.php';
require_once __DIR__ . '/bans/context.php';
require_once __DIR__ . '/bans/handlers_message.php';
require_once __DIR__ . '/bans/handlers_callback.php';
require_once __DIR__ . '/bans/router.php';

use Proxbet\Line\Env;
use Proxbet\Line\Logger;
use Proxbet\Line\Db;

Env::load(__DIR__ . '/../.env');
Logger::init();

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
if ($token === '') {
    Logger::error('TELEGRAM_BOT_TOKEN is not set in .env');
    exit(1);
}

$adminIdsRaw = getenv('TELEGRAM_ADMIN_IDS') ?: '';
if (trim($adminIdsRaw) === '') {
    Logger::error('TELEGRAM_ADMIN_IDS is not set in .env (required)');
    exit(1);
}

$adminIds = array_values(array_filter(array_map(
    static fn($x) => (int) trim($x),
    explode(',', $adminIdsRaw)
), static fn($x) => $x > 0));

if ($adminIds === []) {
    Logger::error('TELEGRAM_ADMIN_IDS parsed empty (check format like 123,456)');
    exit(1);
}

$apiBase = 'https://api.telegram.org/bot' . $token;
$pollTimeout = (int) (getenv('TELEGRAM_POLL_TIMEOUT') ?: 25);
$pollTimeout = max(5, min(50, $pollTimeout));

$statePath = getenv('TELEGRAM_BOT_STATE_PATH') ?: (__DIR__ . '/telegram_state.json');
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

try {
    $db = Db::connectFromEnv();
} catch (Throwable $e) {
    Logger::error('DB connect failed for telegram bot', ['error' => $e->getMessage()]);
    exit(1);
}

while (true) {
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
        // Small backoff to avoid tight loop on errors
        usleep(500 * 1000);
    }
}
