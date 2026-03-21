<?php

declare(strict_types=1);

namespace Proxbet\Core\Interfaces;

/**
 * Base repository interface for data access.
 * Provides common CRUD operations.
 */
interface RepositoryInterface
{
    /**
     * Find entity by ID.
     * 
     * @param int $id Entity ID
     * @return array<string,mixed>|null Entity data or null if not found
     */
    public function findById(int $id): ?array;

    /**
     * Find all entities matching criteria.
     * 
     * @param array<string,mixed> $criteria Search criteria
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return array<int,array<string,mixed>> Array of entities
     */
    public function findBy(array $criteria = [], int $limit = 100, int $offset = 0): array;

    /**
     * Save entity (insert or update).
     * 
     * @param array<string,mixed> $data Entity data
     * @return int Entity ID
     */
    public function save(array $data): int;

    /**
     * Delete entity by ID.
     * 
     * @param int $id Entity ID
     * @return bool True if deleted, false otherwise
     */
    public function delete(int $id): bool;
}
