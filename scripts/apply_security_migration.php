<?php

declare(strict_types=1);

/**
 * Apply security migration for Phase 1
 */

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';

use Proxbet\Line\Db;

proxbet_bootstrap_env();

try {
    $pdo = Db::connectFromEnv();
    
    echo "Applying security migration...\n";
    
    $sql = file_get_contents(__DIR__ . '/../backend/security/migrations/001_encrypt_gemini_keys.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        $pdo->exec($statement);
    }
    
    echo "✅ Migration applied successfully!\n";
    exit(0);
} catch (\Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
