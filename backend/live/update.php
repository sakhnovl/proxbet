<?php

declare(strict_types=1);

namespace Proxbet\Live;

require_once __DIR__ . '/../line/logger.php';

use PDO;
use Proxbet\Line\Logger;

final class Update
{
    private static function touchLiveUpdatedAt(PDO $pdo, string $evid): void
    {
        $stmt = $pdo->prepare('UPDATE `matches` SET `live_updated_at`=CURRENT_TIMESTAMP WHERE `evid`=?');
        $stmt->execute([$evid]);
    }

    public static function updateLiveEvidIfNull(PDO $pdo, string $evid, ?string $liveEvid): bool
    {
        if ($liveEvid === null || $liveEvid === '') {
            return false;
        }

        $stmt = $pdo->prepare('UPDATE `matches` SET `live_evid`=? WHERE `evid`=? AND `live_evid` IS NULL');
        $stmt->execute([$liveEvid, $evid]);
        $changed = $stmt->rowCount() > 0;

        if ($changed) {
            Logger::info('live_evid updated', ['evid' => $evid, 'live_evid' => $liveEvid]);
        }

        return $changed;
    }

    /**
     * @param array{live_ht_hscore:int,live_ht_ascore:int,live_hscore:int,live_ascore:int} $scores
     */
    public static function updateScores(PDO $pdo, string $evid, array $scores): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE `matches` '
            . 'SET `live_ht_hscore`=?,`live_ht_ascore`=?,`live_hscore`=?,`live_ascore`=? '
            . 'WHERE `evid`=?'
        );
        $stmt->execute([
            $scores['live_ht_hscore'],
            $scores['live_ht_ascore'],
            $scores['live_hscore'],
            $scores['live_ascore'],
            $evid,
        ]);

        $changed = $stmt->rowCount() > 0;
        if ($changed) {
            self::touchLiveUpdatedAt($pdo, $evid);
        }

        return $changed;
    }

    /**
     * @param array{time:string,match_status:string} $timeAndStatus
     */
    public static function updateTimeAndStatus(PDO $pdo, string $evid, array $timeAndStatus): bool
    {
        $stmt = $pdo->prepare('UPDATE `matches` SET `time`=?,`match_status`=? WHERE `evid`=?');
        $stmt->execute([
            $timeAndStatus['time'],
            $timeAndStatus['match_status'],
            $evid,
        ]);

        $changed = $stmt->rowCount() > 0;
        if ($changed) {
            self::touchLiveUpdatedAt($pdo, $evid);
        }

        return $changed;
    }

    /**
     * @param array<string,?float> $stats
     * @return int updated fields count
     */
    public static function updateStats(PDO $pdo, string $evid, array $stats): int
    {
        if ($stats === []) {
            return 0;
        }

        $fields = array_keys($stats);
        $setParts = [];
        foreach ($fields as $f) {
            // field names are from internal mapping, so safe
            $setParts[] = '`' . $f . '`=?';
        }

        $sql = 'UPDATE `matches` SET ' . implode(',', $setParts) . ' WHERE `evid`=?';
        $stmt = $pdo->prepare($sql);

        $params = array_values($stats);
        $params[] = $evid;
        $stmt->execute($params);

        $changed = $stmt->rowCount() > 0;
        if ($changed) {
            self::touchLiveUpdatedAt($pdo, $evid);
        }

        // MySQL returns 0 when values not changed. We want count of provided fields instead.
        $updatedFields = 0;
        foreach ($stats as $v) {
            // field considered updated even if null.
            $updatedFields++;
        }

        Logger::info('Live stats updated', ['evid' => $evid, 'fields' => $updatedFields]);

        return $updatedFields;
    }
}
