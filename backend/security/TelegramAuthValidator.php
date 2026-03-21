<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * Telegram authentication validator with HMAC signature verification
 * Provides enhanced security for Telegram bot admin commands
 */
class TelegramAuthValidator
{
    private string $botToken;
    /** @var array<int> */
    private array $adminIds;
    private int $maxAuthAge = 86400; // 24 hours

    /**
     * @param string $botToken Telegram bot token
     * @param array<int> $adminIds List of admin user IDs
     */
    public function __construct(string $botToken, array $adminIds)
    {
        $this->botToken = $botToken;
        $this->adminIds = $adminIds;
    }

    /**
     * Validate admin access with enhanced security
     * 
     * @param int $userId Telegram user ID
     * @param array<string,mixed> $authData Additional auth data (hash, auth_date, etc.)
     * @return bool True if user is authorized admin
     */
    public function isAdmin(int $userId, array $authData = []): bool
    {
        // Basic ID check
        if (!in_array($userId, $this->adminIds, true)) {
            return false;
        }

        // If auth data provided, validate signature
        if (!empty($authData)) {
            return $this->validateAuthData($authData);
        }

        return true;
    }

    /**
     * Validate Telegram webhook signature
     * 
     * @param string $signature X-Telegram-Bot-Api-Secret-Token header value
     * @param string $expectedToken Expected secret token
     * @return bool True if signature is valid
     */
    public function validateWebhookSignature(string $signature, string $expectedToken): bool
    {
        return hash_equals($expectedToken, $signature);
    }

    /**
     * Validate Telegram login widget auth data
     * 
     * @param array<string,mixed> $authData Auth data from Telegram
     * @return bool True if auth data is valid
     */
    public function validateAuthData(array $authData): bool
    {
        if (!isset($authData['hash'], $authData['auth_date'])) {
            return false;
        }

        // Check auth age
        $authDate = (int) $authData['auth_date'];
        if (time() - $authDate > $this->maxAuthAge) {
            return false;
        }

        // Verify hash
        $checkHash = $authData['hash'];
        unset($authData['hash']);

        $dataCheckArr = [];
        foreach ($authData as $key => $value) {
            $dataCheckArr[] = $key . '=' . $value;
        }
        sort($dataCheckArr);
        $dataCheckString = implode("\n", $dataCheckArr);

        $secretKey = hash('sha256', $this->botToken, true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($hash, $checkHash);
    }

    /**
     * Generate admin session token
     * 
     * @param int $userId User ID
     * @param int $expiresIn Token lifetime in seconds
     * @return string Session token
     */
    public function generateSessionToken(int $userId, int $expiresIn = 3600): string
    {
        $payload = [
            'user_id' => $userId,
            'expires_at' => time() + $expiresIn,
            'nonce' => bin2hex(random_bytes(16))
        ];

        $data = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $data, $this->botToken);

        return base64_encode($data . '.' . $signature);
    }

    /**
     * Validate admin session token
     * 
     * @param string $token Session token
     * @return int|null User ID if valid, null otherwise
     */
    public function validateSessionToken(string $token): ?int
    {
        try {
            $decoded = base64_decode($token, true);
            if ($decoded === false) {
                return null;
            }

            $parts = explode('.', $decoded);
            if (count($parts) !== 2) {
                return null;
            }

            [$data, $signature] = $parts;
            $expectedSignature = hash_hmac('sha256', $data, $this->botToken);

            if (!hash_equals($expectedSignature, $signature)) {
                return null;
            }

            $payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            
            if (!isset($payload['user_id'], $payload['expires_at'])) {
                return null;
            }

            if (time() > $payload['expires_at']) {
                return null;
            }

            $userId = (int) $payload['user_id'];
            if (!in_array($userId, $this->adminIds, true)) {
                return null;
            }

            return $userId;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
