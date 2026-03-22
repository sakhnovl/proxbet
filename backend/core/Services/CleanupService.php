<?php

declare(strict_types=1);

namespace Proxbet\Core\Services;

final class CleanupService
{
    private const FINISHED_STATUS = 'Игра завершена';
    private const MIN_SECONDS = 5400;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{
     *   deleted_matches:int,
     *   deleted_snapshots:int,
     *   details:array<int,array{
     *     evid:mixed,
     *     home:mixed,
     *     away:mixed,
     *     time:mixed,
     *     snapshots_deleted:int
     *   }>
     * }
     */
    public function cleanupFinishedMatches(): array
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT `id`, `evid`, `home`, `away`, `time`, `match_status` '
                . 'FROM `matches` '
                . 'WHERE `match_status` = ? '
                . 'AND `time` IS NOT NULL '
                . 'AND ('
                . '  (CAST(SUBSTRING_INDEX(`time`, \':\', 1) AS UNSIGNED) * 60)'
                . '  + CAST(SUBSTRING_INDEX(`time`, \':\', -1) AS UNSIGNED)'
                . ') >= ?'
            );
            $stmt->execute([self::FINISHED_STATUS, self::MIN_SECONDS]);
            $matches = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $deleteSnapshots = $this->pdo->prepare('DELETE FROM `live_match_snapshots` WHERE `match_id` = ?');
            $deleteMatch = $this->pdo->prepare('DELETE FROM `matches` WHERE `id` = ?');

            $deletedSnapshots = 0;
            $deletedMatches = 0;
            $details = [];

            foreach ($matches as $match) {
                $matchId = (int) ($match['id'] ?? 0);
                if ($matchId <= 0) {
                    continue;
                }

                $deleteSnapshots->execute([$matchId]);
                $snapshotsDeleted = $deleteSnapshots->rowCount();
                $deletedSnapshots += $snapshotsDeleted;

                $deleteMatch->execute([$matchId]);
                $deletedMatches += $deleteMatch->rowCount();

                $details[] = [
                    'evid' => $match['evid'] ?? null,
                    'home' => $match['home'] ?? null,
                    'away' => $match['away'] ?? null,
                    'time' => $match['time'] ?? null,
                    'snapshots_deleted' => $snapshotsDeleted,
                ];
            }

            $this->pdo->commit();

            return [
                'deleted_matches' => $deletedMatches,
                'deleted_snapshots' => $deletedSnapshots,
                'details' => $details,
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
