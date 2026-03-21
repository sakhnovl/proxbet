<?php
require_once __DIR__ . '/backend/bootstrap/autoload.php';

try {
    $db = require __DIR__ . '/backend/bootstrap/runtime.php';
    $stmt = $db->query("SHOW COLUMNS FROM matches LIKE 'algorithm_version'");
    
    if ($stmt->rowCount() > 0) {
        echo "✓ Migration applied: algorithm_version column exists\n";
    } else {
        echo "✗ Migration NOT applied: algorithm_version column missing\n";
    }
    
    $stmt = $db->query("SHOW COLUMNS FROM matches LIKE 'live_score_components'");
    if ($stmt->rowCount() > 0) {
        echo "✓ Migration applied: live_score_components column exists\n";
    } else {
        echo "✗ Migration NOT applied: live_score_components column missing\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
