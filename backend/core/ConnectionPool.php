<?php

declare(strict_types=1);

namespace Proxbet\Core;

use PDO;
use RuntimeException;

/**
 * Database connection pool for improved performance.
 * Reuses connections and manages connection lifecycle.
 */
final class ConnectionPool
{
    private static ?self $instance = null;

    /** @var array<string,PDO> */
    private array $connections = [];

    /** @var array<string,int> */
    private array $connectionUsage = [];

    private int $maxConnections = 10;
    private int $maxIdleTime = 300; // 5 minutes

    private function __construct()
    {
        $maxConn = getenv('DB_POOL_MAX_CONNECTIONS');
        if ($maxConn !== false && is_numeric($maxConn)) {
            $this->maxConnections = max(1, (int) $maxConn);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Get a connection from the pool.
     */
    public function getConnection(): PDO
    {
        $connectionKey = $this->buildConnectionKey();

        // Return existing connection if available
        if (isset($this->connections[$connectionKey])) {
            $this->connectionUsage[$connectionKey] = time();
            return $this->connections[$connectionKey];
        }

        // Clean up idle connections if pool is full
        if (count($this->connections) >= $this->maxConnections) {
            $this->cleanupIdleConnections();
        }

        // Create new connection
        $pdo = $this->createConnection();
        $this->connections[$connectionKey] = $pdo;
        $this->connectionUsage[$connectionKey] = time();

        return $pdo;
    }

    /**
     * Release a connection back to the pool.
     */
    public function releaseConnection(PDO $pdo): void
    {
        // Connection remains in pool for reuse
        // Just update usage time
        $connectionKey = $this->findConnectionKey($pdo);
        if ($connectionKey !== null) {
            $this->connectionUsage[$connectionKey] = time();
        }
    }

    /**
     * Get pool statistics.
     *
     * @return array<string,mixed>
     */
    public function getStats(): array
    {
        return [
            'total_connections' => count($this->connections),
            'max_connections' => $this->maxConnections,
            'active_connections' => count($this->connectionUsage),
            'idle_connections' => $this->countIdleConnections(),
        ];
    }

    /**
     * Close all connections in the pool.
     */
    public function closeAll(): void
    {
        $this->connections = [];
        $this->connectionUsage = [];
    }

    private function createConnection(): PDO
    {
        $host = getenv('DB_HOST') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';
        $db = getenv('DB_NAME') ?: '';
        $port = getenv('DB_PORT') ?: '3306';

        if ($host === '' || $user === '' || $db === '') {
            throw new RuntimeException('Database credentials not configured');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true, // Enable persistent connections
        ]);

        return $pdo;
    }

    private function buildConnectionKey(): string
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $db = getenv('DB_NAME') ?: 'proxbet';
        $user = getenv('DB_USER') ?: 'root';

        return md5("{$host}:{$db}:{$user}");
    }

    private function findConnectionKey(PDO $pdo): ?string
    {
        foreach ($this->connections as $key => $conn) {
            if ($conn === $pdo) {
                return $key;
            }
        }

        return null;
    }

    private function cleanupIdleConnections(): void
    {
        $now = time();
        $toRemove = [];

        foreach ($this->connectionUsage as $key => $lastUsed) {
            if ($now - $lastUsed > $this->maxIdleTime) {
                $toRemove[] = $key;
            }
        }

        foreach ($toRemove as $key) {
            unset($this->connections[$key]);
            unset($this->connectionUsage[$key]);
        }
    }

    private function countIdleConnections(): int
    {
        $now = time();
        $idle = 0;

        foreach ($this->connectionUsage as $lastUsed) {
            if ($now - $lastUsed > 60) { // Idle for more than 1 minute
                $idle++;
            }
        }

        return $idle;
    }
}
