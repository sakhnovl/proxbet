<?php

declare(strict_types=1);

namespace Proxbet\Security;

use Proxbet\Core\Exceptions\SecurityException;

/**
 * Secrets Rotation Management System
 * 
 * Manages rotation of sensitive credentials and API keys
 */
class SecretsRotation
{
    private \PDO $pdo;
    private AuditLogger $auditLogger;
    private Encryption $encryption;
    
    private const ROTATION_WARNING_DAYS = 7;
    private const ROTATION_REQUIRED_DAYS = 30;
    
    public function __construct(\PDO $pdo, AuditLogger $auditLogger, Encryption $encryption)
    {
        $this->pdo = $pdo;
        $this->auditLogger = $auditLogger;
        $this->encryption = $encryption;
    }
    
    /**
     * Check which secrets need rotation
     */
    public function checkRotationStatus(): array
    {
        $secrets = $this->getAllSecrets();
        $needsRotation = [];
        $warningRotation = [];
        
        foreach ($secrets as $secret) {
            $daysSinceRotation = $this->getDaysSinceRotation($secret);
            
            if ($daysSinceRotation >= self::ROTATION_REQUIRED_DAYS) {
                $needsRotation[] = [
                    'type' => $secret['type'],
                    'name' => $secret['name'],
                    'days_old' => $daysSinceRotation,
                    'severity' => 'critical'
                ];
            } elseif ($daysSinceRotation >= (self::ROTATION_REQUIRED_DAYS - self::ROTATION_WARNING_DAYS)) {
                $warningRotation[] = [
                    'type' => $secret['type'],
                    'name' => $secret['name'],
                    'days_old' => $daysSinceRotation,
                    'severity' => 'warning'
                ];
            }
        }
        
        return [
            'needs_rotation' => $needsRotation,
            'warning_rotation' => $warningRotation,
            'total_secrets' => count($secrets)
        ];
    }
    
    /**
     * Rotate Gemini API key
     */
    public function rotateGeminiKey(int $userId, string $newApiKey): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT encrypted_key FROM gemini_api_keys WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $oldKey = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$oldKey) {
            throw new SecurityException('Gemini API key not found');
        }
        
        // Encrypt new key
        $encryptedKey = $this->encryption->encrypt($newApiKey);
        
        // Update with new key
        $stmt = $this->pdo->prepare(
            'UPDATE gemini_api_keys 
             SET encrypted_key = ?, last_rotated_at = NOW() 
             WHERE user_id = ?'
        );
        $stmt->execute([$encryptedKey, $userId]);
        
        $this->auditLogger->log('gemini_key_rotated', [
            'user_id' => $userId,
            'rotated_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Generate rotation reminder notifications
     */
    public function generateRotationReminders(): array
    {
        $status = $this->checkRotationStatus();
        $reminders = [];
        
        foreach ($status['needs_rotation'] as $secret) {
            $reminders[] = [
                'priority' => 'high',
                'message' => sprintf(
                    'CRITICAL: %s "%s" requires immediate rotation (%d days old)',
                    $secret['type'],
                    $secret['name'],
                    $secret['days_old']
                )
            ];
        }
        
        foreach ($status['warning_rotation'] as $secret) {
            $reminders[] = [
                'priority' => 'medium',
                'message' => sprintf(
                    'WARNING: %s "%s" should be rotated soon (%d days old)',
                    $secret['type'],
                    $secret['name'],
                    $secret['days_old']
                )
            ];
        }
        
        return $reminders;
    }
    
    /**
     * Record secret rotation
     */
    public function recordRotation(string $secretType, string $secretName): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO secrets_rotation_log (secret_type, secret_name, rotated_at) 
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE rotated_at = NOW()'
        );
        
        $stmt->execute([$secretType, $secretName]);
        
        $this->auditLogger->log('secret_rotated', [
            'type' => $secretType,
            'name' => $secretName
        ]);
    }
    
    /**
     * Get rotation history
     */
    public function getRotationHistory(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT secret_type, secret_name, rotated_at 
             FROM secrets_rotation_log 
             ORDER BY rotated_at DESC 
             LIMIT ?'
        );
        
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Validate secret strength
     */
    public function validateSecretStrength(string $secret, string $type): array
    {
        $issues = [];
        
        // Minimum length check
        $minLength = $this->getMinLength($type);
        if (strlen($secret) < $minLength) {
            $issues[] = "Secret too short (minimum {$minLength} characters)";
        }
        
        // Complexity check
        if (!preg_match('/[A-Z]/', $secret)) {
            $issues[] = 'Missing uppercase letters';
        }
        if (!preg_match('/[a-z]/', $secret)) {
            $issues[] = 'Missing lowercase letters';
        }
        if (!preg_match('/[0-9]/', $secret)) {
            $issues[] = 'Missing numbers';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $secret)) {
            $issues[] = 'Missing special characters';
        }
        
        // Common patterns check
        if (preg_match('/^(password|admin|secret|key)/i', $secret)) {
            $issues[] = 'Contains common weak patterns';
        }
        
        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'strength' => $this->calculateStrength($secret)
        ];
    }
    
    /**
     * Get all tracked secrets
     */
    private function getAllSecrets(): array
    {
        $secrets = [];
        
        // Gemini API keys
        $stmt = $this->pdo->query(
            'SELECT user_id as id, "gemini_api_key" as type, 
                    CONCAT("User ", user_id) as name, last_rotated_at 
             FROM gemini_api_keys'
        );
        $secrets = array_merge($secrets, $stmt->fetchAll(\PDO::FETCH_ASSOC));
        
        // API keys
        $stmt = $this->pdo->query(
            'SELECT id, "api_key" as type, name, created_at as last_rotated_at 
             FROM api_keys WHERE is_active = 1'
        );
        $secrets = array_merge($secrets, $stmt->fetchAll(\PDO::FETCH_ASSOC));
        
        return $secrets;
    }
    
    /**
     * Calculate days since last rotation
     */
    private function getDaysSinceRotation(array $secret): int
    {
        $lastRotation = $secret['last_rotated_at'] ?? null;
        if (!$lastRotation) {
            return 999; // Very old
        }
        
        $lastRotationTime = strtotime($lastRotation);
        $now = time();
        
        return (int)floor(($now - $lastRotationTime) / 86400);
    }
    
    /**
     * Get minimum length for secret type
     */
    private function getMinLength(string $type): int
    {
        return match($type) {
            'api_key' => 32,
            'password' => 12,
            'token' => 32,
            'encryption_key' => 32,
            default => 16
        };
    }
    
    /**
     * Calculate secret strength score (0-100)
     */
    private function calculateStrength(string $secret): int
    {
        $score = 0;
        
        // Length bonus
        $score += min(strlen($secret) * 2, 40);
        
        // Character variety
        if (preg_match('/[A-Z]/', $secret)) $score += 15;
        if (preg_match('/[a-z]/', $secret)) $score += 15;
        if (preg_match('/[0-9]/', $secret)) $score += 15;
        if (preg_match('/[^A-Za-z0-9]/', $secret)) $score += 15;
        
        return min($score, 100);
    }
}
