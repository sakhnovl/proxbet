<?php

declare(strict_types=1);

namespace Proxbet\Security;

use Proxbet\Core\Exceptions\DatabaseException;
use Proxbet\Core\Exceptions\ValidationException;

/**
 * Secure Query Builder
 * 
 * Provides a fluent interface for building SQL queries with built-in security features:
 * - Automatic parameterization
 * - Whitelist validation for table/column names
 * - Query complexity limits
 * - Automatic LIMIT enforcement
 */
class QueryBuilder
{
    private \PDO $pdo;
    private DatabaseQueryGuard $queryGuard;
    
    private string $table = '';
    private array $select = ['*'];
    private array $where = [];
    private array $params = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $joins = [];
    
    private const MAX_LIMIT = 1000;
    private const DEFAULT_LIMIT = 100;
    
    // Whitelist of allowed tables
    private const ALLOWED_TABLES = [
        'matches',
        'match_statistics',
        'bans',
        'gemini_keys',
        'bet_messages',
        'ai_analysis_requests',
        'api_keys',
        'audit_log',
        'secrets_rotation_log'
    ];
    
    // Whitelist of allowed operators
    private const ALLOWED_OPERATORS = [
        '=', '!=', '<>', '>', '<', '>=', '<=',
        'LIKE', 'NOT LIKE', 'IN', 'NOT IN',
        'IS NULL', 'IS NOT NULL',
        'BETWEEN'
    ];
    
    public function __construct(\PDO $pdo, DatabaseQueryGuard $queryGuard)
    {
        $this->pdo = $pdo;
        $this->queryGuard = $queryGuard;
    }
    
    /**
     * Set table for query
     */
    public function table(string $table): self
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new ValidationException("Table '$table' is not in whitelist");
        }
        
        $this->table = $table;
        return $this;
    }
    
    /**
     * Set SELECT columns
     */
    public function select(array $columns): self
    {
        // Validate column names (alphanumeric + underscore only)
        foreach ($columns as $column) {
            if (!preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)?$/', $column) && $column !== '*') {
                throw new ValidationException("Invalid column name: $column");
            }
        }
        
        $this->select = $columns;
        return $this;
    }
    
    /**
     * Add WHERE condition
     */
    public function where(string $column, string $operator, mixed $value = null): self
    {
        // If only 2 params, assume operator is '='
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $operator = strtoupper($operator);
        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new ValidationException("Operator '$operator' is not allowed");
        }
        
        // Validate column name
        if (!preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)?$/', $column)) {
            throw new ValidationException("Invalid column name: $column");
        }
        
        $paramName = 'param_' . count($this->params);
        
        if ($operator === 'IN' || $operator === 'NOT IN') {
            if (!is_array($value)) {
                throw new ValidationException("Value for $operator must be an array");
            }
            $placeholders = [];
            foreach ($value as $i => $val) {
                $placeholders[] = ":${paramName}_$i";
                $this->params["${paramName}_$i"] = $val;
            }
            $this->where[] = "$column $operator (" . implode(', ', $placeholders) . ")";
        } elseif ($operator === 'BETWEEN') {
            if (!is_array($value) || count($value) !== 2) {
                throw new ValidationException("Value for BETWEEN must be array with 2 elements");
            }
            $this->where[] = "$column BETWEEN :${paramName}_start AND :${paramName}_end";
            $this->params["${paramName}_start"] = $value[0];
            $this->params["${paramName}_end"] = $value[1];
        } elseif ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
            $this->where[] = "$column $operator";
        } else {
            $this->where[] = "$column $operator :$paramName";
            $this->params[$paramName] = $value;
        }
        
        return $this;
    }
    
    /**
     * Add ORDER BY
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        // Validate column name
        if (!preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)?$/', $column)) {
            throw new ValidationException("Invalid column name: $column");
        }
        
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new ValidationException("Invalid sort direction: $direction");
        }
        
        $this->orderBy[] = "$column $direction";
        return $this;
    }
    
    /**
     * Set LIMIT
     */
    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new ValidationException("LIMIT must be positive");
        }
        
        if ($limit > self::MAX_LIMIT) {
            throw new ValidationException("LIMIT cannot exceed " . self::MAX_LIMIT);
        }
        
        $this->limit = $limit;
        return $this;
    }
    
    /**
     * Set OFFSET
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new ValidationException("OFFSET must be non-negative");
        }
        
        $this->offset = $offset;
        return $this;
    }
    
    /**
     * Add JOIN
     */
    public function join(string $table, string $condition, string $type = 'INNER'): self
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new ValidationException("Table '$table' is not in whitelist");
        }
        
        $type = strtoupper($type);
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'], true)) {
            throw new ValidationException("Invalid JOIN type: $type");
        }
        
        // Validate condition (basic check)
        if (!preg_match('/^[a-zA-Z0-9_.]+ = [a-zA-Z0-9_.]+$/', $condition)) {
            throw new ValidationException("Invalid JOIN condition");
        }
        
        $this->joins[] = "$type JOIN $table ON $condition";
        return $this;
    }
    
    /**
     * Execute SELECT query and return results
     */
    public function get(): array
    {
        $sql = $this->buildSelectQuery();
        
        $stmt = $this->queryGuard->executeQuery($sql, $this->params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Execute SELECT query and return first result
     */
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        
        return $results[0] ?? null;
    }
    
    /**
     * Execute COUNT query
     */
    public function count(): int
    {
        $originalSelect = $this->select;
        $this->select = ['COUNT(*) as count'];
        
        $sql = $this->buildSelectQuery(false); // Don't add LIMIT for COUNT
        $result = $this->queryGuard->executeQuery($sql, $this->params)->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->select = $originalSelect;
        
        return (int)($result[0]['count'] ?? 0);
    }
    
    /**
     * Execute INSERT query
     */
    public function insert(array $data): int
    {
        if (empty($data)) {
            throw new ValidationException("Insert data cannot be empty");
        }
        
        if (empty($this->table)) {
            throw new ValidationException("Table not specified");
        }
        
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":$col", $columns);
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        
        $this->queryGuard->executeQuery($sql, $data);
        
        return (int)$this->pdo->lastInsertId();
    }
    
    /**
     * Execute batch INSERT
     */
    public function insertBatch(array $rows): int
    {
        if (empty($rows)) {
            throw new ValidationException("Insert data cannot be empty");
        }
        
        if (empty($this->table)) {
            throw new ValidationException("Table not specified");
        }
        
        $columns = array_keys($rows[0]);
        $placeholders = '(' . implode(', ', array_map(fn($col) => ":$col", $columns)) . ')';
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES %s",
            $this->table,
            implode(', ', $columns),
            $placeholders
        );
        
        $count = 0;
        
        foreach ($rows as $row) {
            $this->queryGuard->executeQuery($sql, $row);
            $count++;
        }
        
        return $count;
    }
    
    /**
     * Execute UPDATE query
     */
    public function update(array $data): int
    {
        if (empty($data)) {
            throw new ValidationException("Update data cannot be empty");
        }
        
        if (empty($this->table)) {
            throw new ValidationException("Table not specified");
        }
        
        if (empty($this->where)) {
            throw new ValidationException("UPDATE without WHERE is not allowed");
        }
        
        $sets = [];
        foreach ($data as $column => $value) {
            $paramName = "set_$column";
            $sets[] = "$column = :$paramName";
            $this->params[$paramName] = $value;
        }
        
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $this->table,
            implode(', ', $sets),
            implode(' AND ', $this->where)
        );
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        
        return $stmt->rowCount();
    }
    
    /**
     * Execute DELETE query
     */
    public function delete(): int
    {
        if (empty($this->table)) {
            throw new ValidationException("Table not specified");
        }
        
        if (empty($this->where)) {
            throw new ValidationException("DELETE without WHERE is not allowed");
        }
        
        $sql = sprintf(
            "DELETE FROM %s WHERE %s",
            $this->table,
            implode(' AND ', $this->where)
        );
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        
        return $stmt->rowCount();
    }
    
    /**
     * Build SELECT query
     */
    private function buildSelectQuery(bool $addLimit = true): string
    {
        if (empty($this->table)) {
            throw new ValidationException("Table not specified");
        }
        
        $sql = sprintf(
            "SELECT %s FROM %s",
            implode(', ', $this->select),
            $this->table
        );
        
        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }
        
        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        
        if ($addLimit) {
            $limit = $this->limit ?? self::DEFAULT_LIMIT;
            $sql .= " LIMIT $limit";
            
            if ($this->offset !== null) {
                $sql .= " OFFSET {$this->offset}";
            }
        }
        
        return $sql;
    }
    
    /**
     * Reset builder state
     */
    public function reset(): self
    {
        $this->table = '';
        $this->select = ['*'];
        $this->where = [];
        $this->params = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
        $this->joins = [];
        
        return $this;
    }
}
