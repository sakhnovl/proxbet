<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';

use Proxbet\Line\Db;

proxbet_bootstrap_env();

try {
    $pdo = Db::connectFromEnv();
    
    echo "Checking database structure...\n\n";
    
    // Check if gemini_api_keys table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'gemini_api_keys'");
    if ($stmt->rowCount() === 0) {
        echo "❌ Table 'gemini_api_keys' does NOT exist!\n";
        echo "   Creating table...\n";
        
        $createTable = "
        CREATE TABLE IF NOT EXISTS `gemini_api_keys` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `api_key` VARCHAR(500) NOT NULL,
            `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `last_error` TEXT NULL,
            `fail_count` INT NOT NULL DEFAULT 0,
            `last_used_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_api_key` (`api_key`(255)),
            INDEX `idx_is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($createTable);
        echo "✅ Table created!\n\n";
    } else {
        echo "✅ Table 'gemini_api_keys' exists\n\n";
    }
    
    // Show current structure
    echo "Current table structure:\n";
    echo "------------------------\n";
    $stmt = $pdo->query("DESCRIBE `gemini_api_keys`");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})";
        if ($col['Null'] === 'NO') echo " NOT NULL";
        if ($col['Default'] !== null) echo " DEFAULT {$col['Default']}";
        echo "\n";
    }
    
    echo "\n";
    
    // Check if is_encrypted column exists
    $hasEncrypted = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'is_encrypted') {
            $hasEncrypted = true;
            break;
        }
    }
    
    if ($hasEncrypted) {
        echo "✅ Column 'is_encrypted' exists\n";
    } else {
        echo "❌ Column 'is_encrypted' does NOT exist\n";
        echo "   Adding column...\n";
        
        $pdo->exec("ALTER TABLE `gemini_api_keys` ADD COLUMN `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `api_key`");
        $pdo->exec("ALTER TABLE `gemini_api_keys` ADD INDEX `idx_is_active` (`is_active`)");
        
        echo "✅ Column added!\n";
    }
    
    // Count records
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `gemini_api_keys`");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "\nTotal records in table: $count\n";
    
} catch (\Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
