<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';

use Proxbet\Line\Db;
use Proxbet\Line\Logger;
use Proxbet\Live\LiveService;

/**
 * If a match reached 90:00+ but still isn't marked as finished and there were no live updates
 * for more than 5 minutes, force-finish it in DB.
 */
function forceFinishStaleMatches(PDO $pdo): int
{
    $ended = 'Игра завершена';
    $minSeconds = 90 * 60;

    $sql = 'UPDATE `matches` '
        . 'SET `time`=?, `match_status`=? '
        . 'WHERE COALESCE(`match_status`, \'\') <> ? '
        . 'AND `time` IS NOT NULL '
        . 'AND ('
        . '  (CAST(SUBSTRING_INDEX(`time`, \':\', 1) AS UNSIGNED) * 60)'
        . '  + CAST(SUBSTRING_INDEX(`time`, \':\', -1) AS UNSIGNED)'
        . ') >= ? '
        . 'AND `live_updated_at` IS NOT NULL '
        . 'AND `live_updated_at` < (CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['90:00', $ended, $ended, $minSeconds]);

    return $stmt->rowCount();
}

proxbet_bootstrap_env();
Logger::init();

try {
    $db = Db::connectFromEnv();
    LiveService::run($db);

    $forced = forceFinishStaleMatches($db);
    if ($forced > 0) {
        Logger::info('Forced finish for stale live matches', ['count' => $forced]);
    }
} catch (Throwable $e) {
    Logger::error('Live parser failed', ['error' => $e->getMessage()]);
    exit(1);
}

exit(0);
