<?php

declare(strict_types=1);

namespace Proxbet\Core\Services;

use PDO;
use Proxbet\Core\Exceptions\DatabaseException;
use Proxbet\Core\Interfaces\CacheInterface;
use Proxbet\Line\Logger;

/**
 * Service for ban-related business logic.
 */
final class BanService
{
    private const CACHE_KEY = 'active_bans';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private PDO $pdo,
        private ?CacheInterface $cache = null
    ) {
    }

    /**
     * Get all active bans.
     *
     * @return array<int,array<string,mixed>>
     * @throws DatabaseException
     */
    public function getActiveBans(): array
    {
        // Try cache first
        if ($this->cache !== null && $this->cache->has(self::CACHE_KEY)) {
            $cached = $this->cache->get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // Fetch from database
        try {
            $stmt = $this->pdo->query(
                'SELECT id, country, liga, home, away, is_active, created_at, updated_at 
                FROM bans 
                WHERE is_active = 1 
                ORDER BY id ASC'
            );
            
            $bans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $bans = is_array($bans) ? $bans : [];

            // Cache the result
            if ($this->cache !== null) {
                $this->cache->set(self::CACHE_KEY, $bans, self::CACHE_TTL);
            }

            return $bans;
        } catch (\PDOException $e) {
            Logger::error('Failed to fetch active bans', ['error' => $e->getMessage()]);
            throw new DatabaseException('Failed to fetch active bans: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Invalidate bans cache.
     *
     * @return void
     */
    public function invalidateCache(): void
    {
        if ($this->cache !== null) {
            $this->cache->delete(self::CACHE_KEY);
        }
    }

    /**
     * Add new ban.
     *
     * @param array<string,mixed> $data
     * @return int Ban ID
     * @throws DatabaseException
     */
    public function addBan(array $data): int
    {
        try {
            $isActive = isset($data['is_active']) ? (int) ((bool) $data['is_active']) : 1;

            $stmt = $this->pdo->prepare(
                'INSERT INTO bans (country, liga, home, away, is_active) VALUES (?, ?, ?, ?, ?)'
            );
            
            $stmt->execute([
                $data['country'] ?? null,
                $data['liga'] ?? null,
                $data['home'] ?? null,
                $data['away'] ?? null,
                $isActive,
            ]);

            $this->invalidateCache();

            return (int) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            Logger::error('Failed to add ban', ['error' => $e->getMessage()]);
            throw new DatabaseException('Failed to add ban: ' . $e->getMessage(), 0, $e);
        }
    }
}
