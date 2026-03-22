<?php

declare(strict_types=1);

namespace Proxbet\Line;

use Proxbet\Core\Database\PdoQueryHelper;
use Proxbet\Core\Interfaces\RepositoryInterface;

/**
 * Repository for match data access.
 * Encapsulates database operations for matches.
 */
final class MatchRepository implements RepositoryInterface
{
    public function __construct(
        private \PDO $db
    ) {
    }

    /**
     * Find match by ID.
     * 
     * @param int $id Match ID
     * @return array<string,mixed>|null Match data or null if not found
     */
    public function findById(int $id): ?array
    {
        return PdoQueryHelper::fetchOne(
            $this->db,
            'SELECT * FROM `matches` WHERE `id` = :id LIMIT 1',
            [':id' => $id],
            [':id' => \PDO::PARAM_INT]
        );
    }

    /**
     * Find all matches matching criteria.
     * 
     * @param array<string,mixed> $criteria Search criteria
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return array<int,array<string,mixed>> Array of matches
     */
    public function findBy(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $where = PdoQueryHelper::buildEqualsWhere($criteria);
        $params = $where['params'] + [
            ':limit' => max(1, $limit),
            ':offset' => max(0, $offset),
        ];
        $types = $where['types'] + [
            ':limit' => \PDO::PARAM_INT,
            ':offset' => \PDO::PARAM_INT,
        ];

        return PdoQueryHelper::fetchAll(
            $this->db,
            'SELECT * FROM `matches`' . $where['sql'] . ' LIMIT :limit OFFSET :offset',
            $params,
            $types
        );
    }

    /**
     * Get active matches for scanning.
     * 
     * @return array<int,array<string,mixed>> Array of active matches
     */
    public function getActiveMatches(): array
    {
        $sql = "
            SELECT * FROM matches
            WHERE match_status IN ('1st Half', 'Перерыв', '2nd Half')
            AND stats_fetch_status = 'completed'
            ORDER BY start_time ASC
        ";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Save match (insert or update).
     * 
     * @param array<string,mixed> $data Match data
     * @return int Match ID
     */
    public function save(array $data): int
    {
        if (isset($data['id']) && $data['id'] > 0) {
            return $this->update($data);
        }
        
        return $this->insert($data);
    }

    /**
     * Insert new match.
     * 
     * @param array<string,mixed> $data Match data
     * @return int Match ID
     */
    private function insert(array $data): int
    {
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":{$f}", $fields);
        
        $sql = sprintf(
            'INSERT INTO matches (%s) VALUES (%s)',
            implode(', ', $fields),
            implode(', ', $placeholders)
        );
        
        PdoQueryHelper::execute($this->db, $sql, $this->normalizeNamedParams($data));
        
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update existing match.
     * 
     * @param array<string,mixed> $data Match data (must include 'id')
     * @return int Match ID
     */
    private function update(array $data): int
    {
        $id = (int) $data['id'];
        unset($data['id']);
        
        $sets = array_map(fn($f) => "{$f} = :{$f}", array_keys($data));
        
        $sql = sprintf(
            'UPDATE matches SET %s WHERE id = :id',
            implode(', ', $sets)
        );
        
        $data['id'] = $id;
        PdoQueryHelper::execute($this->db, $sql, $this->normalizeNamedParams($data));
        
        return $id;
    }

    /**
     * Delete match by ID.
     * 
     * @param int $id Match ID
     * @return bool True if deleted, false otherwise
     */
    public function delete(int $id): bool
    {
        return PdoQueryHelper::execute(
            $this->db,
            'DELETE FROM `matches` WHERE `id` = :id',
            [':id' => $id],
            [':id' => \PDO::PARAM_INT]
        ) > 0;
    }

    /**
     * Batch upsert matches.
     * 
     * @param array<int,array<string,mixed>> $matches Array of match data
     * @return array{inserted:int,updated:int,skipped:int} Statistics
     */
    public function batchUpsert(array $matches): array
    {
        if (empty($matches)) {
            return ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        }

        return Db::upsertMatches($this->db, $matches);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function normalizeNamedParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            $normalized[':' . ltrim((string) $key, ':')] = $value;
        }

        return $normalized;
    }
}
