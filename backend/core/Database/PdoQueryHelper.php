<?php

declare(strict_types=1);

namespace Proxbet\Core\Database;

final class PdoQueryHelper
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,int> $types
     * @return array<int,array<string,mixed>>
     */
    public static function fetchAll(\PDO $pdo, string $sql, array $params = [], array $types = []): array
    {
        $stmt = $pdo->prepare($sql);
        self::bindValues($stmt, $params, $types);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,int> $types
     * @return array<string,mixed>|null
     */
    public static function fetchOne(\PDO $pdo, string $sql, array $params = [], array $types = []): ?array
    {
        $stmt = $pdo->prepare($sql);
        self::bindValues($stmt, $params, $types);
        $stmt->execute();

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,int> $types
     */
    public static function execute(\PDO $pdo, string $sql, array $params = [], array $types = []): int
    {
        $stmt = $pdo->prepare($sql);
        self::bindValues($stmt, $params, $types);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,int> $types
     */
    public static function bindValues(\PDOStatement $stmt, array $params, array $types = []): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $types[$key] ?? self::detectType($value));
        }
    }

    /**
     * @param array<string,mixed> $criteria
     * @return array{sql:string,params:array<string,mixed>,types:array<string,int>}
     */
    public static function buildEqualsWhere(array $criteria): array
    {
        $parts = [];
        $params = [];
        $types = [];

        foreach ($criteria as $column => $value) {
            $placeholder = ':where_' . $column;
            $parts[] = sprintf('`%s` = %s', str_replace('`', '``', $column), $placeholder);
            $params[$placeholder] = $value;
            $types[$placeholder] = self::detectType($value);
        }

        return [
            'sql' => $parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts),
            'params' => $params,
            'types' => $types,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string>|null $allowedColumns
     * @return array{sql:string,params:array<string,mixed>,types:array<string,int>}
     */
    public static function buildUpdatePairs(array $data, ?array $allowedColumns = null, string $prefix = 'set'): array
    {
        $pairs = [];
        $params = [];
        $types = [];
        $allowed = $allowedColumns === null ? null : array_flip($allowedColumns);

        foreach ($data as $column => $value) {
            if ($allowed !== null && !isset($allowed[$column])) {
                continue;
            }

            $placeholder = ':' . $prefix . '_' . $column;
            $pairs[] = sprintf('`%s`=%s', str_replace('`', '``', $column), $placeholder);
            $params[$placeholder] = $value;
            $types[$placeholder] = self::detectType($value);
        }

        return [
            'sql' => implode(', ', $pairs),
            'params' => $params,
            'types' => $types,
        ];
    }

    private static function detectType(mixed $value): int
    {
        return match (true) {
            $value === null => \PDO::PARAM_NULL,
            is_int($value) => \PDO::PARAM_INT,
            is_bool($value) => \PDO::PARAM_BOOL,
            default => \PDO::PARAM_STR,
        };
    }
}
