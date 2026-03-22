<?php

declare(strict_types=1);

namespace Proxbet\Security;

require_once __DIR__ . '/LogFilter.php';

use PDO;
use Proxbet\Core\Database\PdoQueryHelper;

/**
 * Audit logging for admin actions and security events
 */
final class AuditLogger
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Log admin action
     */
    public function logAdminAction(
        string $action,
        string $userId,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?array $metadata = null,
        ?string $ipAddress = null
    ): void {
        $this->writeLog('admin_action', $action, $userId, $resourceType, $resourceId, $metadata, $ipAddress);
    }

    /**
     * Log security event (failed login, rate limit, etc.)
     */
    public function logSecurityEvent(
        string $event,
        ?string $userId = null,
        ?array $metadata = null,
        ?string $ipAddress = null
    ): void {
        $this->writeLog('security_event', $event, $userId, null, null, $metadata, $ipAddress);
    }

    /**
     * Generic audit log entry for legacy callers.
     *
     * @param array<string,mixed>|null $metadata
     */
    public function log(
        string $action,
        ?array $metadata = null,
        ?string $userId = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $ipAddress = null,
        string $eventType = 'security_event'
    ): void {
        $this->writeLog($eventType, $action, $userId, $resourceType, $resourceId, $metadata, $ipAddress);
    }

    /**
     * Log authentication attempt
     */
    public function logAuthAttempt(
        bool $success,
        ?string $userId = null,
        ?string $ipAddress = null,
        ?string $reason = null
    ): void {
        $this->writeLog(
            'auth_attempt',
            $success ? 'login_success' : 'login_failed',
            $userId,
            null,
            null,
            ['success' => $success, 'reason' => $reason],
            $ipAddress
        );
    }

    /**
     * Get audit logs with filtering
     */
    public function getLogs(
        ?string $eventType = null,
        ?string $userId = null,
        ?int $limit = 100,
        ?int $offset = 0
    ): array {
        $where = [];
        $params = [];

        if ($eventType !== null) {
            $where[] = 'event_type = :event_type';
            $params[':event_type'] = $eventType;
        }

        if ($userId !== null) {
            $where[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT * FROM audit_logs $whereSql ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        return PdoQueryHelper::fetchAll(
            $this->pdo,
            $sql,
            $params + [':limit' => $limit, ':offset' => $offset],
            [':limit' => PDO::PARAM_INT, ':offset' => PDO::PARAM_INT]
        );
    }

    private function writeLog(
        string $eventType,
        string $action,
        ?string $userId,
        ?string $resourceType,
        ?int $resourceId,
        ?array $metadata,
        ?string $ipAddress
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO audit_logs (event_type, action, user_id, resource_type, resource_id, metadata, ip_address, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );

            $stmt->execute([
                $eventType,
                $action,
                $userId,
                $resourceType,
                $resourceId,
                $metadata !== null ? json_encode(LogFilter::filterArray($metadata), JSON_THROW_ON_ERROR) : null,
                $ipAddress ?? $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (\Throwable $e) {
            // Don't fail the main operation if audit logging fails
            error_log("Audit log failed: " . $e->getMessage());
        }
    }
}
