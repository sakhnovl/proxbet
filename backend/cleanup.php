<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';

use Proxbet\Line\Db;
use Proxbet\Line\Logger;

/**
 * Clean up finished matches from the database.
 * 
 * Deletes matches where:
 * - time >= "90:00" (90 minutes or more)
 * - match_status = "Игра завершена"
 * 
 * Also deletes all related live_match_snapshots for each match.
 * 
 * @param PDO $pdo Database connection
 * @return array Statistics about deleted records
 */
function cleanupFinishedMatches(PDO $pdo): array
{
    $finishedStatus = 'Игра завершена';
    $minSeconds = 90 * 60; // 90 minutes in seconds

    // Start transaction for atomicity
    $pdo->beginTransaction();

    try {
        // Find finished matches that meet deletion criteria
        $sql = 'SELECT id, evid, home, away, time, match_status '
            . 'FROM matches '
            . 'WHERE match_status = ? '
            . 'AND time IS NOT NULL '
            . 'AND ('
            . '  (CAST(SUBSTRING_INDEX(time, \':\', 1) AS UNSIGNED) * 60)'
            . '  + CAST(SUBSTRING_INDEX(time, \':\', -1) AS UNSIGNED)'
            . ') >= ?';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$finishedStatus, $minSeconds]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $deletedSnapshots = 0;
        $deletedMatches = 0;
        $details = [];

        foreach ($matches as $match) {
            // Delete snapshots first (foreign key relationship)
            $stmtSnapshots = $pdo->prepare('DELETE FROM live_match_snapshots WHERE match_id = ?');
            $stmtSnapshots->execute([$match['id']]);
            $snapshotsCount = $stmtSnapshots->rowCount();
            $deletedSnapshots += $snapshotsCount;

            // Delete the match itself
            $stmtMatch = $pdo->prepare('DELETE FROM matches WHERE id = ?');
            $stmtMatch->execute([$match['id']]);
            $deletedMatches += $stmtMatch->rowCount();

            $details[] = [
                'evid' => $match['evid'],
                'home' => $match['home'],
                'away' => $match['away'],
                'time' => $match['time'],
                'snapshots_deleted' => $snapshotsCount,
            ];
        }

        // Commit transaction
        $pdo->commit();

        return [
            'deleted_matches' => $deletedMatches,
            'deleted_snapshots' => $deletedSnapshots,
            'details' => $details,
        ];
    } catch (Throwable $e) {
        // Rollback on any error
        $pdo->rollBack();
        throw $e;
    }
}

// Bootstrap environment and logger
proxbet_bootstrap_env();
Logger::init();

try {
    $db = Db::connectFromEnv();
    $result = cleanupFinishedMatches($db);

    if ($result['deleted_matches'] > 0) {
        Logger::info('Cleanup finished matches', [
            'deleted_matches' => $result['deleted_matches'],
            'deleted_snapshots' => $result['deleted_snapshots'],
        ]);

        foreach ($result['details'] as $detail) {
            Logger::info('Deleted match', $detail);
        }
    }

    exit(0);
} catch (Throwable $e) {
    Logger::error('Cleanup failed', ['error' => $e->getMessage()]);
    exit(1);
}
