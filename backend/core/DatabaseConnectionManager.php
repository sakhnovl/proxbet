<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Core\Exceptions\DatabaseException;

/**
 * Database Connection Manager with automatic reconnection and failover
 */
class DatabaseConnectionManager
{
    private array $config;
    private ?\PDO $connection = null;
    private StructuredLogger $logger;
    private RetryHandler $retryHandler;
    private int $reconnectAttempts = 0;
    private int $maxReconnectAttempts = 3;
    private ?string $lastError = null;

    public function __construct(array $config, StructuredLogger $logger, RetryHandler $retryHandler)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->retryHandler = $retryHandler;
    }

    /**
     * Get database connection with automatic reconnection
     */
    public function getConnection(): \PDO
    {
        if ($this->connection === null || !$this->isConnectionAlive()) {
            $this->connect();
        }

        return $this->connection;
    }

    /**
     * Establish database connection with retry logic
     */
    private function connect(): void
    {
        try {
            $this->connection = $this->retryHandler->execute(
                fn() => $this->createConnection(),
                'database_connection'
            );

            $this->reconnectAttempts = 0;
            $this->lastError = null;

            $this->logger->info('Database connection established', [
                'host' => $this->config['host'] ?? 'unknown'
            ]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logger->error('Failed to establish database connection', [
                'error' => $e->getMessage(),
                'attempts' => $this->reconnectAttempts
            ]);

            throw new DatabaseException(
                'Database connection failed after retries: ' . $e->getMessage(),
                0,
                false,
                [],
                $e
            );
        }
    }

    /**
     * Create PDO connection
     */
    private function createConnection(): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['host'] ?? 'localhost',
            $this->config['port'] ?? 3306,
            $this->config['database'] ?? ''
        );

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_PERSISTENT => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            \PDO::ATTR_TIMEOUT => 5
        ];

        return new \PDO(
            $dsn,
            $this->config['username'] ?? '',
            $this->config['password'] ?? '',
            $options
        );
    }

    /**
     * Check if connection is alive
     */
    private function isConnectionAlive(): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            $this->connection->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            $this->logger->warning('Database connection lost', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Execute query with automatic reconnection
     */
    public function executeWithReconnect(callable $callback): mixed
    {
        try {
            return $callback($this->getConnection());
        } catch (\PDOException $e) {
            if ($this->isConnectionError($e) && $this->reconnectAttempts < $this->maxReconnectAttempts) {
                $this->reconnectAttempts++;
                $this->logger->info('Attempting to reconnect to database', [
                    'attempt' => $this->reconnectAttempts
                ]);

                $this->connection = null;
                return $callback($this->getConnection());
            }

            throw new DatabaseException('Database query failed: ' . $e->getMessage(), 0, false, [], $e);
        }
    }

    /**
     * Check if exception is a connection error
     */
    private function isConnectionError(\PDOException $e): bool
    {
        $connectionErrors = [
            2002, // Connection refused
            2006, // MySQL server has gone away
            2013, // Lost connection to MySQL server
            1040, // Too many connections
            1053  // Server shutdown in progress
        ];

        return in_array((int)$e->getCode(), $connectionErrors, true) ||
               str_contains($e->getMessage(), 'server has gone away') ||
               str_contains($e->getMessage(), 'Lost connection');
    }

    /**
     * Close connection
     */
    public function close(): void
    {
        $this->connection = null;
        $this->logger->debug('Database connection closed');
    }

    /**
     * Get connection status
     */
    public function getStatus(): array
    {
        return [
            'connected' => $this->connection !== null && $this->isConnectionAlive(),
            'reconnect_attempts' => $this->reconnectAttempts,
            'last_error' => $this->lastError
        ];
    }
}
