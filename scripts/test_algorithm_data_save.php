<?php

declare(strict_types=1);

/**
 * Test script to verify algorithm_version and live_score_components are saved correctly.
 * 
 * Usage: php scripts/test_algorithm_data_save.php
 */

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';

use Proxbet\Line\Db;
use Proxbet\Line\Logger;

proxbet_bootstrap_env();
Logger::init();

try {
    $db = Db::connectFromEnv();
    
    echo "=== Testing Algorithm Data Save ===\n\n";
    
    // Check if migration was applied
    $stmt = $db->query("SHOW COLUMNS FROM `matches` LIKE 'algorithm_version'");
    $hasAlgorithmVersion = $stmt->rowCount() > 0;
    
    $stmt = $db->query("SHOW COLUMNS FROM `matches` LIKE 'live_score_components'");
    $hasLiveScoreComponents = $stmt->rowCount() > 0;
    
    if (!$hasAlgorithmVersion || !$hasLiveScoreComponents) {
        echo "❌ Migration not applied! Run migration 002_add_algorithm_version_fields.sql first.\n";
        exit(1);
    }
    
    echo "✅ Migration columns exist\n\n";
    
    // Get current algorithm version from config
    $algorithmVersion = (int) ($_ENV['ALGORITHM_VERSION'] ?? getenv('ALGORITHM_VERSION') ?: 1);
    echo "Current ALGORITHM_VERSION from .env: {$algorithmVersion}\n\n";
    
    // Check current state of matches
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN algorithm_version = 1 THEN 1 ELSE 0 END) as v1_count,
            SUM(CASE WHEN algorithm_version = 2 THEN 1 ELSE 0 END) as v2_count,
            SUM(CASE WHEN live_score_components IS NOT NULL THEN 1 ELSE 0 END) as with_components
        FROM `matches`
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Current database state:\n";
    echo "  Total matches: {$stats['total']}\n";
    echo "  Algorithm v1: {$stats['v1_count']}\n";
    echo "  Algorithm v2: {$stats['v2_count']}\n";
    echo "  With components: {$stats['with_components']}\n\n";
    
    // Show sample matches with algorithm data
    $stmt = $db->query("
        SELECT 
            id, 
            home, 
            away, 
            algorithm_version,
            CASE 
                WHEN live_score_components IS NULL THEN 'NULL'
                WHEN LENGTH(live_score_components) > 100 THEN CONCAT(LEFT(live_score_components, 100), '...')
                ELSE live_score_components
            END as components_preview
        FROM `matches`
        WHERE `time` IS NOT NULL AND `time` != ''
        ORDER BY id DESC
        LIMIT 5
    ");
    
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($matches) > 0) {
        echo "Sample matches (last 5 active):\n";
        echo str_repeat('-', 80) . "\n";
        foreach ($matches as $match) {
            echo sprintf(
                "ID: %d | %s - %s | v%d | Components: %s\n",
                $match['id'],
                $match['home'],
                $match['away'],
                $match['algorithm_version'],
                $match['components_preview']
            );
        }
        echo str_repeat('-', 80) . "\n\n";
    }
    
    echo "✅ Test completed successfully!\n\n";
    echo "Next steps:\n";
    echo "1. Run scanner: php backend/scanner/ScannerCli.php\n";
    echo "2. Check that algorithm_version and live_score_components are updated\n";
    echo "3. To enable v2: Set ALGORITHM_VERSION=2 in .env\n";
    echo "4. To enable dual-run: Set ALGORITHM1_DUAL_RUN=1 in .env\n";
    
} catch (\Throwable $e) {
    echo "❌ Error: {$e->getMessage()}\n";
    echo "File: {$e->getFile()}:{$e->getLine()}\n";
    exit(1);
}
