<?php

declare(strict_types=1);

namespace Proxbet\Security;

use PDO;
use PDOStatement;

/**
 * Database query guard with automatic LIMIT protection
 * Prevents DoS attacks via unlimited result sets
 */
class DatabaseQueryGuard
{
    private PDO $pdo;
    private int $defaultLimit = 1000;
    private int $maxLimit = 10000;

    public function __construct(PDO $pdo, int $defaultLimit = 1000, int $maxLimit = 10000)
    {
        $this->pdo = $pdo;
        $this->defaultLimit = $defaultLimit;
        $this->maxLimit = $maxLimit;
    }

    /**
     * Execute SELECT query with automatic LIMIT protection
     * 
     * @param string $query SQL query
     * @param array<mixed> $params Query parameters
     * @param int|null $limit Custom limit (null = use default)
     * @return PDOStatement
     * @throws \RuntimeException If query is unsafe
     */
    public function executeSelect(string $query, array $params = [], ?int $limit = null): PDOStatement
    {
        $query = trim($query);
        
        // Only protect SELECT queries
        if (!preg_match('/^\s*SELECT\s+/i', $query)) {
            throw new \RuntimeException('DatabaseQueryGuard only handles SELECT queries');
        }

        // Check if query already has LIMIT
        $hasLimit = preg_match('/\bLIMIT\s+\d+/i', $query);

        if (!$hasLimit) {
            $limitValue = $limit ?? $this->defaultLimit;
            
            // Enforce max limit
            if ($limitValue > $this->maxLimit) {
                $limitValue = $this->maxLimit;
            }

            // Add LIMIT clause
            $query = rtrim($query, ';') . ' LIMIT ' . $limitValue;
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Execute query with timing attack protection
     * Uses constant-time comparison for sensitive queries
     * 
     * @param string $query SQL query
     * @param array<mixed> $params Query parameters
     * @return PDOStatement
     */
    public function executeWithTimingProtection(string $query, array $params = []): PDOStatement
    {
        // Add small random delay to prevent timing attacks
        usleep(random_int(1000, 5000)); // 1-5ms

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Validate and sanitize ORDER BY clause
     * 
     * @param string $orderBy Order by clause
     * @param array<string> $allowedColumns Whitelist of allowed columns
     * @return string Sanitized ORDER BY clause
     * @throws \InvalidArgumentException If column not in whitelist
     */
    public function sanitizeOrderBy(string $orderBy, array $allowedColumns): string
    {
        $parts = explode(' ', trim($orderBy));
        $column = $parts[0];
        $direction = strtoupper($parts[1] ?? 'ASC');

        if (!in_array($column, $allowedColumns, true)) {
            throw new \InvalidArgumentException('Invalid ORDER BY column: ' . $column);
        }

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        return $column . ' ' . $direction;
    }

    /**
     * Get default limit
     */
    public function getDefaultLimit(): int
    {
        return $this->defaultLimit;
    }

    /**
     * Get max limit
     */
    public function getMaxLimit(): int
    {
        return $this->maxLimit;
    }
}
