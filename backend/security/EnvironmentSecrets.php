<?php

declare(strict_types=1);

namespace Proxbet\Security;

use Proxbet\Core\Exceptions\SecurityException;

/**
 * Environment-Specific Secrets Manager
 * 
 * Manages different secrets for different environments (dev, staging, production)
 * Automatically loads the correct secrets based on APP_ENV
 */
class EnvironmentSecrets
{
    private string $environment;
    private SecretsManager $secretsManager;
    private array $cache = [];
    
    private const ENV_DEVELOPMENT = 'development';
    private const ENV_STAGING = 'staging';
    private const ENV_PRODUCTION = 'production';
    
    private const ALLOWED_ENVIRONMENTS = [
        self::ENV_DEVELOPMENT,
        self::ENV_STAGING,
        self::ENV_PRODUCTION
    ];
    
    public function __construct(string $environment, SecretsManager $secretsManager)
    {
        if (!in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new SecurityException("Invalid environment: $environment");
        }
        
        $this->environment = $environment;
        $this->secretsManager = $secretsManager;
    }
    
    /**
     * Get secret for current environment
     */
    public function get(string $key): ?string
    {
        // Check cache first
        $cacheKey = $this->getCacheKey($key);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        // Try environment-specific key first
        $envKey = $this->getEnvironmentKey($key);
        $value = $this->secretsManager->getSecret($envKey);
        
        // Fallback to non-environment key
        if ($value === null) {
            $value = $this->secretsManager->getSecret($key);
        }
        
        // Cache the result
        $this->cache[$cacheKey] = $value;
        
        return $value;
    }
    
    /**
     * Set secret for current environment
     */
    public function set(string $key, string $value): void
    {
        $envKey = $this->getEnvironmentKey($key);
        $this->secretsManager->setSecret($envKey, $value);
        
        // Clear cache
        $cacheKey = $this->getCacheKey($key);
        unset($this->cache[$cacheKey]);
    }
    
    /**
     * Set secret for specific environment
     */
    public function setForEnvironment(string $environment, string $key, string $value): void
    {
        if (!in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new SecurityException("Invalid environment: $environment");
        }
        
        $envKey = $this->buildEnvironmentKey($environment, $key);
        $this->secretsManager->setSecret($envKey, $value);
    }
    
    /**
     * Get secret for specific environment
     */
    public function getForEnvironment(string $environment, string $key): ?string
    {
        if (!in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new SecurityException("Invalid environment: $environment");
        }
        
        $envKey = $this->buildEnvironmentKey($environment, $key);
        return $this->secretsManager->getSecret($envKey);
    }
    
    /**
     * Delete secret for current environment
     */
    public function delete(string $key): void
    {
        $envKey = $this->getEnvironmentKey($key);
        $this->secretsManager->deleteSecret($envKey);
        
        // Clear cache
        $cacheKey = $this->getCacheKey($key);
        unset($this->cache[$cacheKey]);
    }
    
    /**
     * List all secrets for current environment
     */
    public function list(): array
    {
        $allSecrets = $this->secretsManager->listSecrets();
        $prefix = strtoupper($this->environment) . '_';
        
        $envSecrets = [];
        foreach ($allSecrets as $secret) {
            if (str_starts_with($secret, $prefix)) {
                $envSecrets[] = substr($secret, strlen($prefix));
            }
        }
        
        return $envSecrets;
    }
    
    /**
     * Get current environment
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }
    
    /**
     * Check if running in production
     */
    public function isProduction(): bool
    {
        return $this->environment === self::ENV_PRODUCTION;
    }
    
    /**
     * Check if running in development
     */
    public function isDevelopment(): bool
    {
        return $this->environment === self::ENV_DEVELOPMENT;
    }
    
    /**
     * Check if running in staging
     */
    public function isStaging(): bool
    {
        return $this->environment === self::ENV_STAGING;
    }
    
    /**
     * Get required secret (throws if not found)
     */
    public function getRequired(string $key): string
    {
        $value = $this->get($key);
        
        if ($value === null) {
            throw new SecurityException("Required secret not found: $key (environment: {$this->environment})");
        }
        
        return $value;
    }
    
    /**
     * Get secret with default value
     */
    public function getWithDefault(string $key, string $default): string
    {
        return $this->get($key) ?? $default;
    }
    
    /**
     * Bulk set secrets for current environment
     */
    public function bulkSet(array $secrets): void
    {
        foreach ($secrets as $key => $value) {
            $this->set($key, $value);
        }
    }
    
    /**
     * Copy secrets from one environment to another
     */
    public function copyToEnvironment(string $targetEnvironment, array $keys): void
    {
        if (!in_array($targetEnvironment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new SecurityException("Invalid target environment: $targetEnvironment");
        }
        
        foreach ($keys as $key) {
            $value = $this->get($key);
            if ($value !== null) {
                $this->setForEnvironment($targetEnvironment, $key, $value);
            }
        }
    }
    
    /**
     * Validate that all required secrets exist
     */
    public function validateRequired(array $requiredKeys): array
    {
        $missing = [];
        
        foreach ($requiredKeys as $key) {
            if ($this->get($key) === null) {
                $missing[] = $key;
            }
        }
        
        return $missing;
    }
    
    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
    
    /**
     * Get environment-specific key
     */
    private function getEnvironmentKey(string $key): string
    {
        return $this->buildEnvironmentKey($this->environment, $key);
    }
    
    /**
     * Build environment-specific key
     */
    private function buildEnvironmentKey(string $environment, string $key): string
    {
        return strtoupper($environment) . '_' . $key;
    }
    
    /**
     * Get cache key
     */
    private function getCacheKey(string $key): string
    {
        return $this->environment . ':' . $key;
    }
    
    /**
     * Create instance from environment variable
     */
    public static function fromEnv(SecretsManager $secretsManager): self
    {
        $environment = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: self::ENV_DEVELOPMENT;
        return new self($environment, $secretsManager);
    }
}
