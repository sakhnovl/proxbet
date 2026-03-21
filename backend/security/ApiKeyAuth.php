<?php

declare(strict_types=1);

namespace Proxbet\Security;

use Proxbet\Core\Exceptions\AuthenticationException;
use Proxbet\Core\Exceptions\ValidationException;

/**
 * API Key Authentication System
 * 
 * Provides API key generation, validation, and management for public endpoints
 */
class ApiKeyAuth
{
    private \PDO $pdo;
    private AuditLogger $auditLogger;
    
    private const KEY_PREFIX = 'pk_';
    private const KEY_LENGTH = 32;
    private const HASH_ALGO = 'sha256';
    
    public function __construct(\PDO $pdo, AuditLogger $auditLogger)
    {
        $this->pdo = $pdo;
        $this->auditLogger = $auditLogger;
    }
    
    /**
     * Generate new API key
     */
    public function generateApiKey(string $name, array $permissions = [], ?int $rateLimit = 100): array
    {
        $key = self::KEY_PREFIX . bin2hex(random_bytes(self::KEY_LENGTH));
        $keyHash = hash(self::HASH_ALGO, $key);
        
        $stmt = $this->pdo->prepare(
            'INSERT INTO api_keys (key_hash, name, permissions, rate_limit, created_at) 
             VALUES (?, ?, ?, ?, NOW())'
        );
        
        $stmt->execute([
            $keyHash,
            $name,
            json_encode($permissions),
            $rateLimit
        ]);
        
        $keyId = (int)$this->pdo->lastInsertId();
        
        $this->auditLogger->log('api_key_generated', [
            'key_id' => $keyId,
            'name' => $name,
            'permissions' => $permissions
        ]);
        
        return [
            'id' => $keyId,
            'key' => $key, // Only returned once!
            'name' => $name,
            'permissions' => $permissions,
            'rate_limit' => $rateLimit
        ];
    }
    
    /**
     * Validate API key and return key info
     */
    public function validateApiKey(string $key): array
    {
        if (!str_starts_with($key, self::KEY_PREFIX)) {
            throw new AuthenticationException('Invalid API key format');
        }
        
        $keyHash = hash(self::HASH_ALGO, $key);
        
        $stmt = $this->pdo->prepare(
            'SELECT id, name, permissions, rate_limit, is_active, last_used_at 
             FROM api_keys 
             WHERE key_hash = ? AND is_active = 1'
        );
        
        $stmt->execute([$keyHash]);
        $keyData = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$keyData) {
            $this->auditLogger->log('api_key_validation_failed', [
                'key_hash' => substr($keyHash, 0, 8) . '...'
            ]);
            throw new AuthenticationException('Invalid or inactive API key');
        }
        
        // Update last used timestamp
        $this->updateLastUsed((int)$keyData['id']);
        
        return [
            'id' => (int)$keyData['id'],
            'name' => $keyData['name'],
            'permissions' => json_decode($keyData['permissions'], true),
            'rate_limit' => (int)$keyData['rate_limit']
        ];
    }
    
    /**
     * Check if API key has specific permission
     */
    public function hasPermission(array $keyData, string $permission): bool
    {
        return in_array($permission, $keyData['permissions'], true) 
            || in_array('*', $keyData['permissions'], true);
    }
    
    /**
     * Revoke API key
     */
    public function revokeApiKey(int $keyId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE api_keys SET is_active = 0, revoked_at = NOW() WHERE id = ?'
        );
        
        $stmt->execute([$keyId]);
        
        $this->auditLogger->log('api_key_revoked', ['key_id' => $keyId]);
    }
    
    /**
     * Rotate API key (revoke old, generate new)
     */
    public function rotateApiKey(int $oldKeyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT name, permissions, rate_limit FROM api_keys WHERE id = ?'
        );
        $stmt->execute([$oldKeyId]);
        $oldKey = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$oldKey) {
            throw new ValidationException('API key not found');
        }
        
        // Revoke old key
        $this->revokeApiKey($oldKeyId);
        
        // Generate new key with same settings
        $newKey = $this->generateApiKey(
            $oldKey['name'],
            json_decode($oldKey['permissions'], true),
            (int)$oldKey['rate_limit']
        );
        
        $this->auditLogger->log('api_key_rotated', [
            'old_key_id' => $oldKeyId,
            'new_key_id' => $newKey['id']
        ]);
        
        return $newKey;
    }
    
    /**
     * Get API key rate limit
     */
    public function getRateLimit(int $keyId): int
    {
        $stmt = $this->pdo->prepare('SELECT rate_limit FROM api_keys WHERE id = ?');
        $stmt->execute([$keyId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $result ? (int)$result['rate_limit'] : 100;
    }
    
    /**
     * List all active API keys (without actual keys)
     */
    public function listApiKeys(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, permissions, rate_limit, created_at, last_used_at 
             FROM api_keys 
             WHERE is_active = 1 
             ORDER BY created_at DESC'
        );
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    private function updateLastUsed(int $keyId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE api_keys SET last_used_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$keyId]);
    }
}
