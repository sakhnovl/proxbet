<?php

declare(strict_types=1);

namespace Proxbet\Core\Services;

use PDO;
use Proxbet\Core\Exceptions\DatabaseException;
use Proxbet\Line\Logger;

/**
 * Service for match-related business logic.
 */
final class MatchService
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * Get active matches for scanning.
     *
     * @return array<int,array<string,mixed>>
     * @throws DatabaseException
     */
    public function getActiveMatches(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT * FROM matches 
                WHERE match_status IN ('Перерыв', '1-й тайм') 
                AND stats_fetch_status = 'completed'
                ORDER BY start_time ASC"
            );
            
            $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($matches) ? $matches : [];
        } catch (\PDOException $e) {
            Logger::error('Failed to fetch active matches', ['error' => $e->getMessage()]);
            throw new DatabaseException('Failed to fetch active matches: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get match by ID.
     *
     * @param int $id
     * @return array<string,mixed>|null
     * @throws DatabaseException
     */
    public function getMatchById(int $id): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM matches WHERE id = ?');
            $stmt->execute([$id]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return is_array($match) ? $match : null;
        } catch (\PDOException $e) {
            Logger::error('Failed to fetch match by ID', ['id' => $id, 'error' => $e->getMessage()]);
            throw new DatabaseException('Failed to fetch match: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update match algorithm data.
     *
     * @param int $matchId
     * @param int $algorithmVersion
     * @param array<string,mixed>|null $components
     * @return bool
     * @throws DatabaseException
     */
    public function updateAlgorithmData(int $matchId, int $algorithmVersion, ?array $components): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE matches SET algorithm_version = ?, algorithm_components = ? WHERE id = ?'
            );
            
            $componentsJson = $components !== null ? json_encode($components) : null;
            $stmt->execute([$algorithmVersion, $componentsJson, $matchId]);
            
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            Logger::error('Failed to update algorithm data', [
                'match_id' => $matchId,
                'error' => $e->getMessage()
            ]);
            throw new DatabaseException('Failed to update algorithm data: ' . $e->getMessage(), 0, $e);
        }
    }
}
