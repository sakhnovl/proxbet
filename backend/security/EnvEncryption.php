<?php

declare(strict_types=1);

namespace Proxbet\Security;

use Proxbet\Core\Exceptions\SecurityException;

/**
 * .env File Encryption Utility
 * 
 * Encrypts sensitive .env files for secure storage
 * Uses AES-256-GCM encryption
 */
class EnvEncryption
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_LENGTH = 32; // 256 bits
    
    private string $encryptionKey;
    
    public function __construct(string $encryptionKey)
    {
        if (strlen($encryptionKey) !== self::KEY_LENGTH) {
            throw new SecurityException('Encryption key must be exactly 32 bytes');
        }
        
        $this->encryptionKey = $encryptionKey;
    }
    
    /**
     * Encrypt .env file
     */
    public function encryptFile(string $inputPath, string $outputPath): void
    {
        if (!file_exists($inputPath)) {
            throw new SecurityException("Input file not found: $inputPath");
        }
        
        $content = file_get_contents($inputPath);
        if ($content === false) {
            throw new SecurityException("Failed to read input file");
        }
        
        $encrypted = $this->encrypt($content);
        
        if (file_put_contents($outputPath, $encrypted) === false) {
            throw new SecurityException("Failed to write encrypted file");
        }
    }
    
    /**
     * Decrypt .env file
     */
    public function decryptFile(string $inputPath, string $outputPath): void
    {
        if (!file_exists($inputPath)) {
            throw new SecurityException("Input file not found: $inputPath");
        }
        
        $encrypted = file_get_contents($inputPath);
        if ($encrypted === false) {
            throw new SecurityException("Failed to read encrypted file");
        }
        
        $decrypted = $this->decrypt($encrypted);
        
        if (file_put_contents($outputPath, $decrypted) === false) {
            throw new SecurityException("Failed to write decrypted file");
        }
    }
    
    /**
     * Encrypt string
     */
    public function encrypt(string $data): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        
        if ($encrypted === false) {
            throw new SecurityException('Encryption failed');
        }
        
        // Combine IV + tag + encrypted data
        return base64_encode($iv . $tag . $encrypted);
    }
    
    /**
     * Decrypt string
     */
    public function decrypt(string $data): string
    {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new SecurityException('Invalid encrypted data format');
        }
        
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16;
        
        if (strlen($decoded) < $ivLength + $tagLength) {
            throw new SecurityException('Invalid encrypted data length');
        }
        
        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, $tagLength);
        $encrypted = substr($decoded, $ivLength + $tagLength);
        
        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($decrypted === false) {
            throw new SecurityException('Decryption failed - invalid key or corrupted data');
        }
        
        return $decrypted;
    }
    
    /**
     * Generate new encryption key
     */
    public static function generateKey(): string
    {
        return random_bytes(self::KEY_LENGTH);
    }
    
    /**
     * Generate key as base64 string
     */
    public static function generateKeyBase64(): string
    {
        return base64_encode(self::generateKey());
    }
    
    /**
     * Load key from file
     */
    public static function loadKeyFromFile(string $path): string
    {
        if (!file_exists($path)) {
            throw new SecurityException("Key file not found: $path");
        }
        
        $key = file_get_contents($path);
        if ($key === false) {
            throw new SecurityException("Failed to read key file");
        }
        
        $key = trim($key);
        
        // Check if base64 encoded
        if (base64_encode(base64_decode($key, true)) === $key) {
            $key = base64_decode($key);
        }
        
        if (strlen($key) !== self::KEY_LENGTH) {
            throw new SecurityException('Invalid key length in file');
        }
        
        return $key;
    }
    
    /**
     * Parse encrypted .env content
     */
    public function parseEncryptedEnv(string $encryptedPath): array
    {
        $decrypted = $this->decrypt(file_get_contents($encryptedPath));
        return $this->parseEnvContent($decrypted);
    }
    
    /**
     * Parse .env content into array
     */
    private function parseEnvContent(string $content): array
    {
        $lines = explode("\n", $content);
        $env = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines and comments
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            
            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                    $value = $matches[2];
                }
                
                $env[$key] = $value;
            }
        }
        
        return $env;
    }
}
