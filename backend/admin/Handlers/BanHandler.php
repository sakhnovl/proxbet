<?php

declare(strict_types=1);

namespace Proxbet\Admin\Handlers;

use Proxbet\Line\Db;
use Proxbet\Security\InputValidator;
use Proxbet\Security\AuditLogger;
use Proxbet\Security\RequestValidator;

/**
 * Ban Handler - handles ban management operations.
 */
final class BanHandler
{
    private \PDO $pdo;
    private AuditLogger $auditLogger;
    private string $clientIp;

    public function __construct(\PDO $pdo, AuditLogger $auditLogger, string $clientIp)
    {
        $this->pdo = $pdo;
        $this->auditLogger = $auditLogger;
        $this->clientIp = $clientIp;
    }

    /**
     * List bans with pagination.
     * 
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function list(int $limit, int $offset): array
    {
        return Db::listBans($this->pdo, $limit, $offset);
    }

    /**
     * Add new ban.
     * 
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public function add(array $data): array
    {
        $sanitized = [
            'country' => InputValidator::sanitizeString($data['country'] ?? null, 255),
            'liga' => InputValidator::sanitizeString($data['liga'] ?? null, 255),
            'home' => InputValidator::sanitizeString($data['home'] ?? null, 255),
            'away' => InputValidator::sanitizeString($data['away'] ?? null, 255),
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : 1,
        ];

        if (array_filter([$sanitized['country'], $sanitized['liga'], $sanitized['home'], $sanitized['away']]) === []) {
            throw new \RuntimeException('At least one field (country, liga, home, away) is required.');
        }

        $id = Db::addBan($this->pdo, $sanitized);
        $ban = Db::getBanById($this->pdo, $id);
        
        $this->auditLogger->logAdminAction('add_ban', 'admin', 'ban', $id, $sanitized, $this->clientIp);
        
        return $ban ?? [];
    }

    /**
     * Update existing ban.
     * 
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public function update(int $id, array $data): array
    {
        $existing = Db::getBanById($this->pdo, $id);
        if ($existing === null) {
            throw new \RuntimeException('Ban not found.');
        }

        $sanitized = [
            'country' => InputValidator::sanitizeString($data['country'] ?? null, 255),
            'liga' => InputValidator::sanitizeString($data['liga'] ?? null, 255),
            'home' => InputValidator::sanitizeString($data['home'] ?? null, 255),
            'away' => InputValidator::sanitizeString($data['away'] ?? null, 255),
        ];

        Db::updateBan($this->pdo, $id, $sanitized);

        if (isset($data['is_active'])) {
            $stmt = $this->pdo->prepare('UPDATE `bans` SET `is_active`=? WHERE `id`=?');
            $stmt->execute([(int) (bool) $data['is_active'], $id]);
        }

        $updated = Db::getBanById($this->pdo, $id);
        
        $this->auditLogger->logAdminAction('update_ban', 'admin', 'ban', $id, $sanitized, $this->clientIp);
        
        return $updated ?? [];
    }

    /**
     * Delete ban.
     * 
     * @return array{deleted_id:int}
     * @throws \RuntimeException
     */
    public function delete(int $id): array
    {
        $existing = Db::getBanById($this->pdo, $id);
        if ($existing === null) {
            throw new \RuntimeException('Ban not found.');
        }

        Db::deleteBan($this->pdo, $id);
        
        $this->auditLogger->logAdminAction('delete_ban', 'admin', 'ban', $id, null, $this->clientIp);
        
        return ['deleted_id' => $id];
    }
}
