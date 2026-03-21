<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * AES-256-GCM encryption for sensitive data (API keys, tokens)
 * 
 * Requires ENCRYPTION_KEY in .env (32 bytes base64-encoded)
 * Generate with: openssl rand -base64 32
 */
final class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \InvalidArgumentException('Encryption key must be 32 bytes (base64-encoded)');
        }
        $this->key = $key;
    }

    /**
     * Encrypt data and return base64-encoded string with IV and tag
     * Format: base64(iv:tag:ciphertext)
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new \InvalidArgumentException('Cannot encrypt empty string');
        }

        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Combine IV + tag + ciphertext and encode
        $combined = $iv . $tag . $ciphertext;
        return base64_encode($combined);
    }

    /**
     * Decrypt base64-encoded encrypted data
     */
    public function decrypt(string $encrypted): string
    {
        $combined = base64_decode($encrypted, true);
        if ($combined === false) {
            throw new \InvalidArgumentException('Invalid encrypted data format');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        
        if (strlen($combined) < $ivLength + self::TAG_LENGTH) {
            throw new \InvalidArgumentException('Encrypted data too short');
        }

        $iv = substr($combined, 0, $ivLength);
        $tag = substr($combined, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($combined, $ivLength + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed - data may be corrupted or tampered');
        }

        return $plaintext;
    }

    /**
     * Create instance from environment variable
     */
    public static function fromEnv(): self
    {
        $key = getenv('ENCRYPTION_KEY');
        if ($key === false || $key === '') {
            throw new \RuntimeException('ENCRYPTION_KEY not set in environment');
        }
        return new self($key);
    }
}
