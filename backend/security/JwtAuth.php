<?php

declare(strict_types=1);

namespace Proxbet\Security;

use RuntimeException;

/**
 * JWT token generation and validation for admin authentication
 * Provides secure token-based authentication with expiration
 */
final class JwtAuth
{
    private const ALGORITHM = 'HS256';
    private const TOKEN_EXPIRATION = 3600; // 1 hour

    /**
     * Generate JWT token for admin user
     */
    public static function generateToken(string $secret, string $userId, int $expiresIn = self::TOKEN_EXPIRATION): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => self::ALGORITHM
        ];

        $payload = [
            'sub' => $userId,
            'iat' => time(),
            'exp' => time() + $expiresIn,
            'iss' => 'proxbet-admin'
        ];

        $headerEncoded = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        
        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secret, true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    /**
     * Validate and decode JWT token
     * Returns payload if valid, null if invalid or expired
     */
    public static function validateToken(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Verify signature
        $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secret, true);
        $expectedSignatureEncoded = self::base64UrlEncode($expectedSignature);

        if (!hash_equals($expectedSignatureEncoded, $signatureEncoded)) {
            return null;
        }

        // Decode payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        if (!is_array($payload)) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * Hash password using bcrypt
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verify password against hash
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
