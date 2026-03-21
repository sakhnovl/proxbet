<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/line/env.php';
require_once __DIR__ . '/../backend/line/db.php';
require_once __DIR__ . '/../backend/line/SchemaBootstrap.php';

use Proxbet\Line\Env;
use Proxbet\Line\Db;
use Proxbet\Line\SchemaBootstrap;

echo "[INFO] Loading environment...\n";
Env::load(__DIR__ . '/../.env');

echo "[INFO] Connecting to database...\n";
$pdo = Db::connectFromEnv();

echo "[INFO] Ensuring base schema exists...\n";
SchemaBootstrap::ensure($pdo);

echo "[INFO] Checking if migration 002 is already applied...\n";
$cols = SchemaBootstrap::getTableColumns($pdo, 'matches');
$hasAlgorithmVersion = in_array('algorithm_version', $cols, true);
$hasLiveScoreComponents = in_array('live_score_components', $cols, true);

if ($hasAlgorithmVersion && $hasLiveScoreComponents) {
    echo "[INFO] Migration 002 already applied. Columns exist:\n";
    echo "  - algorithm_version: YES\n";
    echo "  - live_score_components: YES\n";
    exit(0);
}

echo "[INFO] Applying migration 002_add_algorithm_version_fields.sql...\n";

try {
    $pdo->exec(
        "ALTER TABLE matches
            ADD COLUMN algorithm_version INT NOT NULL DEFAULT 1 COMMENT '1 = legacy, 2 = v2',
            ADD COLUMN live_score_components LONGTEXT NULL COMMENT 'JSON with Algorithm 1 v2 components'"
    );
    echo "[SUCCESS] Added columns algorithm_version and live_score_components\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "[INFO] Columns already exist (partial migration detected)\n";
    } else {
        echo "[ERROR] Failed to add columns: " . $e->getMessage() . "\n";
        exit(1);
    }
}

try {
    if (!SchemaBootstrap::hasTableIndex($pdo, 'matches', 'idx_algorithm_version')) {
        $pdo->exec("CREATE INDEX idx_algorithm_version ON matches(algorithm_version)");
        echo "[SUCCESS] Created index idx_algorithm_version\n";
    } else {
        echo "[INFO] Index idx_algorithm_version already exists\n";
    }
} catch (PDOException $e) {
    echo "[WARNING] Failed to create index: " . $e->getMessage() . "\n";
}

echo "\n[INFO] Verifying migration...\n";
$cols = SchemaBootstrap::getTableColumns($pdo, 'matches');
$hasAlgorithmVersion = in_array('algorithm_version', $cols, true);
$hasLiveScoreComponents = in_array('live_score_components', $cols, true);

echo "  - algorithm_version: " . ($hasAlgorithmVersion ? 'YES' : 'NO') . "\n";
echo "  - live_score_components: " . ($hasLiveScoreComponents ? 'YES' : 'NO') . "\n";

if ($hasAlgorithmVersion && $hasLiveScoreComponents) {
    echo "\n[SUCCESS] Migration 002 completed successfully!\n";
    exit(0);
} else {
    echo "\n[ERROR] Migration 002 incomplete!\n";
    exit(1);
}
