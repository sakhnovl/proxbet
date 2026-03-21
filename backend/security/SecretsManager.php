<?php

declare(strict_types=1);

namespace Proxbet\Security;

use Proxbet\Core\Exceptions\SecurityException;

/**
 * Secrets Manager Abstraction
 * 
 * Provides unified interface for different secrets management backends:
 * - Local encrypted storage
 * - HashiCorp Vault
 * - AWS Secrets Manager
 * - Environment variables
 */
class SecretsManager
{
    private string $backend;
    private array $config;
    private ?EnvEncryption $envEncryption = null;
    
    private const BACKEND_ENV = 'env';
    private const BACKEND_ENCRYPTED_FILE = 'encrypted_file';
    private const BACKEND_VAULT = 'vault';
    private const BACKEND_AWS = 'aws';
    
    public function __construct(string $backend, array $config = [])
    {
        $this->backend = $backend;
        $this->config = $config;
        
        if ($backend === self::BACKEND_ENCRYPTED_FILE) {
            if (!isset($config['encryption_key'])) {
                throw new SecurityException('Encryption key required for encrypted_file backend');
            }
            $this->envEncryption = new EnvEncryption($config['encryption_key']);
        }
    }
    
    /**
     * Get secret value
     */
    public function getSecret(string $key): ?string
    {
        return match($this->backend) {
            self::BACKEND_ENV => $this->getFromEnv($key),
            self::BACKEND_ENCRYPTED_FILE => $this->getFromEncryptedFile($key),
            self::BACKEND_VAULT => $this->getFromVault($key),
            self::BACKEND_AWS => $this->getFromAWS($key),
            default => throw new SecurityException("Unknown backend: {$this->backend}")
        };
    }
    
    /**
     * Set secret value
     */
    public function setSecret(string $key, string $value): void
    {
        match($this->backend) {
            self::BACKEND_ENV => throw new SecurityException('Cannot set env variables at runtime'),
            self::BACKEND_ENCRYPTED_FILE => $this->setInEncryptedFile($key, $value),
            self::BACKEND_VAULT => $this->setInVault($key, $value),
            self::BACKEND_AWS => $this->setInAWS($key, $value),
            default => throw new SecurityException("Unknown backend: {$this->backend}")
        };
    }
    
    /**
     * Delete secret
     */
    public function deleteSecret(string $key): void
    {
        match($this->backend) {
            self::BACKEND_ENV => throw new SecurityException('Cannot delete env variables'),
            self::BACKEND_ENCRYPTED_FILE => $this->deleteFromEncryptedFile($key),
            self::BACKEND_VAULT => $this->deleteFromVault($key),
            self::BACKEND_AWS => $this->deleteFromAWS($key),
            default => throw new SecurityException("Unknown backend: {$this->backend}")
        };
    }
    
    /**
     * List all secret keys
     */
    public function listSecrets(): array
    {
        return match($this->backend) {
            self::BACKEND_ENV => array_keys($_ENV),
            self::BACKEND_ENCRYPTED_FILE => $this->listFromEncryptedFile(),
            self::BACKEND_VAULT => $this->listFromVault(),
            self::BACKEND_AWS => $this->listFromAWS(),
            default => throw new SecurityException("Unknown backend: {$this->backend}")
        };
    }
    
    // Environment variables backend
    private function getFromEnv(string $key): ?string
    {
        return $_ENV[$key] ?? getenv($key) ?: null;
    }
    
    // Encrypted file backend
    private function getFromEncryptedFile(string $key): ?string
    {
        $secrets = $this->loadEncryptedFile();
        return $secrets[$key] ?? null;
    }
    
    private function setInEncryptedFile(string $key, string $value): void
    {
        $secrets = $this->loadEncryptedFile();
        $secrets[$key] = $value;
        $this->saveEncryptedFile($secrets);
    }
    
    private function deleteFromEncryptedFile(string $key): void
    {
        $secrets = $this->loadEncryptedFile();
        unset($secrets[$key]);
        $this->saveEncryptedFile($secrets);
    }
    
    private function listFromEncryptedFile(): array
    {
        $secrets = $this->loadEncryptedFile();
        return array_keys($secrets);
    }
    
    private function loadEncryptedFile(): array
    {
        $path = $this->config['file_path'] ?? '.env.encrypted';
        
        if (!file_exists($path)) {
            return [];
        }
        
        return $this->envEncryption->parseEncryptedEnv($path);
    }
    
    private function saveEncryptedFile(array $secrets): void
    {
        $path = $this->config['file_path'] ?? '.env.encrypted';
        
        $content = '';
        foreach ($secrets as $key => $value) {
            $content .= "$key=$value\n";
        }
        
        $encrypted = $this->envEncryption->encrypt($content);
        file_put_contents($path, $encrypted);
    }
    
    // HashiCorp Vault backend
    private function getFromVault(string $key): ?string
    {
        $vaultAddr = $this->config['vault_addr'] ?? throw new SecurityException('vault_addr not configured');
        $vaultToken = $this->config['vault_token'] ?? throw new SecurityException('vault_token not configured');
        $secretPath = $this->config['secret_path'] ?? 'secret/data/proxbet';
        
        $url = rtrim($vaultAddr, '/') . '/v1/' . $secretPath;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Vault-Token: ' . $vaultToken,
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        $data = json_decode($response, true);
        return $data['data']['data'][$key] ?? null;
    }
    
    private function setInVault(string $key, string $value): void
    {
        $vaultAddr = $this->config['vault_addr'] ?? throw new SecurityException('vault_addr not configured');
        $vaultToken = $this->config['vault_token'] ?? throw new SecurityException('vault_token not configured');
        $secretPath = $this->config['secret_path'] ?? 'secret/data/proxbet';
        
        // Get existing secrets
        $existing = $this->listFromVault();
        $secrets = [];
        foreach ($existing as $existingKey) {
            $secrets[$existingKey] = $this->getFromVault($existingKey);
        }
        
        // Add/update new secret
        $secrets[$key] = $value;
        
        $url = rtrim($vaultAddr, '/') . '/v1/' . $secretPath;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'X-Vault-Token: ' . $vaultToken,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode(['data' => $secrets])
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 && $httpCode !== 204) {
            throw new SecurityException('Failed to set secret in Vault');
        }
    }
    
    private function deleteFromVault(string $key): void
    {
        // Vault doesn't support deleting individual keys, need to update without the key
        $existing = $this->listFromVault();
        $secrets = [];
        foreach ($existing as $existingKey) {
            if ($existingKey !== $key) {
                $secrets[$existingKey] = $this->getFromVault($existingKey);
            }
        }
        
        $vaultAddr = $this->config['vault_addr'] ?? throw new SecurityException('vault_addr not configured');
        $vaultToken = $this->config['vault_token'] ?? throw new SecurityException('vault_token not configured');
        $secretPath = $this->config['secret_path'] ?? 'secret/data/proxbet';
        
        $url = rtrim($vaultAddr, '/') . '/v1/' . $secretPath;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'X-Vault-Token: ' . $vaultToken,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode(['data' => $secrets])
        ]);
        
        curl_exec($ch);
        curl_close($ch);
    }
    
    private function listFromVault(): array
    {
        $vaultAddr = $this->config['vault_addr'] ?? throw new SecurityException('vault_addr not configured');
        $vaultToken = $this->config['vault_token'] ?? throw new SecurityException('vault_token not configured');
        $secretPath = $this->config['secret_path'] ?? 'secret/data/proxbet';
        
        $url = rtrim($vaultAddr, '/') . '/v1/' . $secretPath;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Vault-Token: ' . $vaultToken,
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return [];
        }
        
        $data = json_decode($response, true);
        return array_keys($data['data']['data'] ?? []);
    }
    
    // AWS Secrets Manager backend (stub - requires AWS SDK)
    private function getFromAWS(string $key): ?string
    {
        throw new SecurityException('AWS Secrets Manager backend requires AWS SDK installation');
    }
    
    private function setInAWS(string $key, string $value): void
    {
        throw new SecurityException('AWS Secrets Manager backend requires AWS SDK installation');
    }
    
    private function deleteFromAWS(string $key): void
    {
        throw new SecurityException('AWS Secrets Manager backend requires AWS SDK installation');
    }
    
    private function listFromAWS(): array
    {
        throw new SecurityException('AWS Secrets Manager backend requires AWS SDK installation');
    }
}
