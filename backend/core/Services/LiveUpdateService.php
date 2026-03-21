<?php

declare(strict_types=1);

namespace Proxbet\Core\Services;

use PDO;
use Proxbet\Line\Logger;
use Proxbet\Live\LiveService;

/**
 * Service for live match updates and maintenance.
 */
class LiveUpdateService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Run live updates for active matches.
     */
    public function runLiveUpdates(): void
    {
        LiveService::run($this->db);
    }

    /**
     * Force finish stale matches that reached 90+ minutes but weren't marked as finished.
     * 
     * If a match reached 90:00+ but still isn't marked as finished and there were no live updates
     * for more than 5 minutes, force-finish it in DB.
     *
     * @return int Number of matches force-finished
     */
    public function forceFinishStaleMatches(): int
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

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['90:00', $ended, $ended, $minSeconds]);

        $count = $stmt->rowCount();
        
        if ($count > 0) {
            Logger::info('Forced finish for stale live matches', ['count' => $count]);
        }

        return $count;
    }
}
