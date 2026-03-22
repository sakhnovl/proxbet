<?php

declare(strict_types=1);

namespace Proxbet\Live;

require_once __DIR__ . '/../line/logger.php';

use PDO;
use Proxbet\Line\Logger;

final class Update
{
    private const TREND_WINDOW_MINUTES = 5;

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

    public static function saveSnapshotAndRefreshTrend(PDO $pdo, string $evid): void
    {
        $match = self::fetchCurrentLiveMatch($pdo, $evid);
        if ($match === null) {
            return;
        }

        $minute = self::parseMinute((string) ($match['time'] ?? ''));
        if ($minute <= 0) {
            return;
        }

        self::saveSnapshot($pdo, $match, $minute);
        self::refreshTrendColumns($pdo, $match, $minute);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function fetchCurrentLiveMatch(PDO $pdo, string $evid): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT `id`, `evid`, `time`, `match_status`, `live_ht_hscore`, `live_ht_ascore`, `live_hscore`, `live_ascore`, '
            . '`live_xg_home`, `live_xg_away`, `live_att_home`, `live_att_away`, '
            . '`live_danger_att_home`, `live_danger_att_away`, '
            . '`live_shots_on_target_home`, `live_shots_on_target_away`, '
            . '`live_shots_off_target_home`, `live_shots_off_target_away`, '
            . '`live_yellow_cards_home`, `live_yellow_cards_away`, '
            . '`live_safe_home`, `live_safe_away`, `live_corner_home`, `live_corner_away` '
            . 'FROM `matches` WHERE `evid` = ? LIMIT 1'
        );
        $stmt->execute([$evid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $match
     */
    private static function saveSnapshot(PDO $pdo, array $match, int $minute): void
    {
        $sql = 'INSERT INTO `live_match_snapshots` ('
            . '`match_id`, `evid`, `minute`, `time`, `match_status`, `live_ht_hscore`, `live_ht_ascore`, `live_hscore`, `live_ascore`, '
            . '`live_xg_home`, `live_xg_away`, `live_att_home`, `live_att_away`, '
            . '`live_danger_att_home`, `live_danger_att_away`, '
            . '`live_shots_on_target_home`, `live_shots_on_target_away`, '
            . '`live_shots_off_target_home`, `live_shots_off_target_away`, '
            . '`live_yellow_cards_home`, `live_yellow_cards_away`, '
            . '`live_safe_home`, `live_safe_away`, `live_corner_home`, `live_corner_away`'
            . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE '
            . '`time` = VALUES(`time`), '
            . '`live_ht_hscore` = VALUES(`live_ht_hscore`), '
            . '`live_ht_ascore` = VALUES(`live_ht_ascore`), '
            . '`live_hscore` = VALUES(`live_hscore`), '
            . '`live_ascore` = VALUES(`live_ascore`), '
            . '`live_xg_home` = VALUES(`live_xg_home`), '
            . '`live_xg_away` = VALUES(`live_xg_away`), '
            . '`live_att_home` = VALUES(`live_att_home`), '
            . '`live_att_away` = VALUES(`live_att_away`), '
            . '`live_danger_att_home` = VALUES(`live_danger_att_home`), '
            . '`live_danger_att_away` = VALUES(`live_danger_att_away`), '
            . '`live_shots_on_target_home` = VALUES(`live_shots_on_target_home`), '
            . '`live_shots_on_target_away` = VALUES(`live_shots_on_target_away`), '
            . '`live_shots_off_target_home` = VALUES(`live_shots_off_target_home`), '
            . '`live_shots_off_target_away` = VALUES(`live_shots_off_target_away`), '
            . '`live_yellow_cards_home` = VALUES(`live_yellow_cards_home`), '
            . '`live_yellow_cards_away` = VALUES(`live_yellow_cards_away`), '
            . '`live_safe_home` = VALUES(`live_safe_home`), '
            . '`live_safe_away` = VALUES(`live_safe_away`), '
            . '`live_corner_home` = VALUES(`live_corner_home`), '
            . '`live_corner_away` = VALUES(`live_corner_away`), '
            . '`captured_at` = CURRENT_TIMESTAMP';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            (int) ($match['id'] ?? 0),
            (string) ($match['evid'] ?? ''),
            $minute,
            $match['time'] ?? null,
            (string) ($match['match_status'] ?? ''),
            self::toNullableInt($match['live_ht_hscore'] ?? null),
            self::toNullableInt($match['live_ht_ascore'] ?? null),
            self::toNullableInt($match['live_hscore'] ?? null),
            self::toNullableInt($match['live_ascore'] ?? null),
            self::toNullableFloat($match['live_xg_home'] ?? null),
            self::toNullableFloat($match['live_xg_away'] ?? null),
            self::toNullableFloat($match['live_att_home'] ?? null),
            self::toNullableFloat($match['live_att_away'] ?? null),
            self::toNullableFloat($match['live_danger_att_home'] ?? null),
            self::toNullableFloat($match['live_danger_att_away'] ?? null),
            self::toNullableFloat($match['live_shots_on_target_home'] ?? null),
            self::toNullableFloat($match['live_shots_on_target_away'] ?? null),
            self::toNullableFloat($match['live_shots_off_target_home'] ?? null),
            self::toNullableFloat($match['live_shots_off_target_away'] ?? null),
            self::toNullableFloat($match['live_yellow_cards_home'] ?? null),
            self::toNullableFloat($match['live_yellow_cards_away'] ?? null),
            self::toNullableFloat($match['live_safe_home'] ?? null),
            self::toNullableFloat($match['live_safe_away'] ?? null),
            self::toNullableFloat($match['live_corner_home'] ?? null),
            self::toNullableFloat($match['live_corner_away'] ?? null),
        ]);
    }

    /**
     * @param array<string,mixed> $match
     */
    private static function refreshTrendColumns(PDO $pdo, array $match, int $minute): void
    {
        $baseline = self::fetchTrendBaseline($pdo, (int) ($match['id'] ?? 0));
        if ($baseline === null) {
            self::writeTrendColumns($pdo, (string) ($match['evid'] ?? ''), [
                'live_trend_shots_total_delta' => null,
                'live_trend_shots_on_target_delta' => null,
                'live_trend_danger_attacks_delta' => null,
                'live_trend_xg_delta' => null,
                'live_trend_window_seconds' => null,
                'live_trend_has_data' => 0,
            ]);
            return;
        }

        $currentShotsTotal = self::sumStats(
            $match['live_shots_on_target_home'] ?? null,
            $match['live_shots_on_target_away'] ?? null,
            $match['live_shots_off_target_home'] ?? null,
            $match['live_shots_off_target_away'] ?? null
        );
        $baselineShotsTotal = self::sumStats(
            $baseline['live_shots_on_target_home'] ?? null,
            $baseline['live_shots_on_target_away'] ?? null,
            $baseline['live_shots_off_target_home'] ?? null,
            $baseline['live_shots_off_target_away'] ?? null
        );

        $currentShotsOnTarget = self::sumStats(
            $match['live_shots_on_target_home'] ?? null,
            $match['live_shots_on_target_away'] ?? null
        );
        $baselineShotsOnTarget = self::sumStats(
            $baseline['live_shots_on_target_home'] ?? null,
            $baseline['live_shots_on_target_away'] ?? null
        );

        $currentDanger = self::sumStats(
            $match['live_danger_att_home'] ?? null,
            $match['live_danger_att_away'] ?? null
        );
        $baselineDanger = self::sumStats(
            $baseline['live_danger_att_home'] ?? null,
            $baseline['live_danger_att_away'] ?? null
        );

        $currentXg = self::sumNullableFloats($match['live_xg_home'] ?? null, $match['live_xg_away'] ?? null);
        $baselineXg = self::sumNullableFloats($baseline['live_xg_home'] ?? null, $baseline['live_xg_away'] ?? null);

        $capturedAt = strtotime((string) ($baseline['captured_at'] ?? ''));
        $windowSeconds = $capturedAt !== false ? max(0, ($minute * 60) - self::snapshotMinuteSeconds((int) ($baseline['minute'] ?? 0))) : null;
        if ($windowSeconds !== null && $windowSeconds <= 0 && $capturedAt !== false) {
            $windowSeconds = max(0, time() - $capturedAt);
        }

        self::writeTrendColumns($pdo, (string) ($match['evid'] ?? ''), [
            'live_trend_shots_total_delta' => max(0, $currentShotsTotal - $baselineShotsTotal),
            'live_trend_shots_on_target_delta' => max(0, $currentShotsOnTarget - $baselineShotsOnTarget),
            'live_trend_danger_attacks_delta' => max(0, $currentDanger - $baselineDanger),
            'live_trend_xg_delta' => $currentXg !== null && $baselineXg !== null
                ? round(max(0.0, $currentXg - $baselineXg), 4)
                : null,
            'live_trend_window_seconds' => $windowSeconds,
            'live_trend_has_data' => 1,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function fetchTrendBaseline(PDO $pdo, int $matchId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM `live_match_snapshots` '
            . 'WHERE `match_id` = ? '
            . 'AND `captured_at` >= (CURRENT_TIMESTAMP - INTERVAL ' . self::TREND_WINDOW_MINUTES . ' MINUTE) '
            . 'ORDER BY `captured_at` ASC LIMIT 1'
        );
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $columns
     */
    private static function writeTrendColumns(PDO $pdo, string $evid, array $columns): void
    {
        $setParts = [];
        $params = [];

        foreach ($columns as $column => $value) {
            $setParts[] = '`' . $column . '` = ?';
            $params[] = $value;
        }

        $params[] = $evid;

        $stmt = $pdo->prepare('UPDATE `matches` SET ' . implode(', ', $setParts) . ' WHERE `evid` = ?');
        $stmt->execute($params);
    }

    private static function parseMinute(string $time): int
    {
        $parts = explode(':', $time);
        if (!is_numeric($parts[0])) {
            return 0;
        }

        return max(0, (int) $parts[0]);
    }

    private static function snapshotMinuteSeconds(int $minute): int
    {
        return max(0, $minute) * 60;
    }

    private static function sumStats(mixed ...$values): int
    {
        $sum = 0.0;
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $sum += (float) $value;
            }
        }

        return (int) $sum;
    }

    private static function sumNullableFloats(mixed ...$values): ?float
    {
        $sum = 0.0;
        $hasValue = false;

        foreach ($values as $value) {
            if (is_numeric($value)) {
                $sum += (float) $value;
                $hasValue = true;
            }
        }

        return $hasValue ? $sum : null;
    }

    private static function toNullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function toNullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
