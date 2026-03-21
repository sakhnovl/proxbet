<?php

declare(strict_types=1);

/**
 * Script to encrypt existing Gemini API keys in database
 * Run once after deploying encryption feature
 * 
 * Usage: php scripts/encrypt_existing_gemini_keys.php
 */

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';
require_once __DIR__ . '/../backend/security/Encryption.php';

use Proxbet\Line\Db;
use Proxbet\Security\Encryption;

proxbet_bootstrap_env();
proxbet_require_env(['ENCRYPTION_KEY', 'DB_HOST', 'DB_USER', 'DB_NAME']);

try {
    $pdo = Db::connectFromEnv();
    $encryption = Encryption::fromEnv();

    // Get all unencrypted keys
    $stmt = $pdo->query(
        'SELECT `id`, `api_key` FROM `gemini_api_keys` WHERE `is_encrypted` = 0'
    );
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($keys)) {
        echo "No unencrypted keys found. All keys are already encrypted.\n";
        exit(0);
    }

    echo "Found " . count($keys) . " unencrypted keys. Starting encryption...\n";

    $updateStmt = $pdo->prepare(
        'UPDATE `gemini_api_keys` SET `api_key` = ?, `is_encrypted` = 1 WHERE `id` = ?'
    );

    $encrypted = 0;
    $failed = 0;

    foreach ($keys as $key) {
        try {
            $encryptedKey = $encryption->encrypt($key['api_key']);
            $updateStmt->execute([$encryptedKey, $key['id']]);
            $encrypted++;
            echo "✓ Encrypted key ID {$key['id']}\n";
        } catch (\Throwable $e) {
            $failed++;
            echo "✗ Failed to encrypt key ID {$key['id']}: {$e->getMessage()}\n";
        }
    }

    echo "\nEncryption complete:\n";
    echo "  Encrypted: $encrypted\n";
    echo "  Failed: $failed\n";

    if ($failed > 0) {
        exit(1);
    }

    exit(0);
} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    exit(1);
}
