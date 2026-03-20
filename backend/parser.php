<?php

declare(strict_types=1);

require_once __DIR__ . '/line/env.php';
require_once __DIR__ . '/line/logger.php';
require_once __DIR__ . '/line/http.php';
require_once __DIR__ . '/line/extractMatches.php';
require_once __DIR__ . '/line/db.php';
require_once __DIR__ . '/line/BanMatcher.php';

use Proxbet\Line\Env;
use Proxbet\Line\Logger;
use Proxbet\Line\Http;
use Proxbet\Line\Db;
use Proxbet\Line\BanMatcher;
use function Proxbet\Line\extractMatches;

Env::load(__DIR__ . '/../.env');
Logger::init();

$apiUrl = getenv('API_URL') ?: '';
if ($apiUrl === '') {
    Logger::error('API_URL is not set in .env');
    exit(1);
}

try {
    $payload = Http::getJson($apiUrl);
} catch (Throwable $e) {
    Logger::error('Failed to fetch API JSON', ['error' => $e->getMessage()]);
    exit(1);
}

$matches = extractMatches($payload);
Logger::info('Extracted matches', ['count' => count($matches)]);

try {
    $db = Db::connectFromEnv();

    // Load bans once per run for performance.
    $bans = Db::getActiveBans($db);
    Logger::info('Loaded bans', ['count' => count($bans)]);

    // Filter matches by bans.
    $debugBans = (int) (getenv('DEBUG_BANS') ?: 0) === 1;
    $debugLimit = max(1, (int) (getenv('DEBUG_BANS_LIMIT') ?: 3));
    $filtered = [];
    $bannedCount = 0;
    foreach ($matches as $m) {
        if (!is_array($m)) {
            continue;
        }

        if ($debugBans && $debugLimit > 0) {
            $debugLimit--;
            foreach ($bans as $banRow) {
                $resDbg = BanMatcher::matchBan($banRow, $m);
                Logger::info('Ban debug', [
                    'evid' => $m['evid'] ?? null,
                    'match' => [
                        'country' => $m['country'] ?? null,
                        'liga' => $m['liga'] ?? null,
                        'home' => $m['home'] ?? null,
                        'away' => $m['away'] ?? null,
                    ],
                    'ban_id' => $banRow['id'] ?? null,
                    'ban' => [
                        'country' => $banRow['country'] ?? null,
                        'liga' => $banRow['liga'] ?? null,
                        'home' => $banRow['home'] ?? null,
                        'away' => $banRow['away'] ?? null,
                    ],
                    'matched' => $resDbg['matched'] ?? false,
                    'matched_fields' => $resDbg['fields'] ?? [],
                ]);
            }
        }

        $res = BanMatcher::matchAny($bans, $m);
        if ($res['matched']) {
            $bannedCount++;
            $ban = $res['ban'] ?? [];

            Logger::info('Match skipped by ban', [
                'evid' => $m['evid'] ?? null,
                'country' => $m['country'] ?? null,
                'liga' => $m['liga'] ?? null,
                'home' => $m['home'] ?? null,
                'away' => $m['away'] ?? null,
                'ban_id' => $ban['id'] ?? null,
                'ban' => [
                    'country' => $ban['country'] ?? null,
                    'liga' => $ban['liga'] ?? null,
                    'home' => $ban['home'] ?? null,
                    'away' => $ban['away'] ?? null,
                ],
                'matched_fields' => $res['fields'] ?? [],
            ]);

            continue;
        }

        $filtered[] = $m;
    }

    Logger::info('Bans filter applied', [
        'total_matches' => count($matches),
        'banned' => $bannedCount,
        'to_upsert' => count($filtered),
    ]);

    $stats = Db::upsertMatches($db, $filtered);
    $stats['banned'] = $bannedCount;
    Logger::info('DB upsert finished', $stats);
} catch (Throwable $e) {
    Logger::error('DB error', ['error' => $e->getMessage()]);
    exit(1);
}

exit(0);
