<?php

declare(strict_types=1);

/**
 * Apply performance optimization migration (003)
 * Adds database indexes for improved query performance
 */

require_once __DIR__ . '/../backend/line/db.php';
require_once __DIR__ . '/../backend/line/logger.php';

use Proxbet\Line\Db;
use Proxbet\Line\Logger;

try {
    Logger::info('Starting performance migration 003');

    $pdo = Db::connectFromEnv();
    
    // Read migration file
    $migrationFile = __DIR__ . '/migrations/003_add_performance_indexes.sql';
    if (!file_exists($migrationFile)) {
        throw new RuntimeException('Migration file not found: ' . $migrationFile);
    }

    $sql = file_get_contents($migrationFile);
    if ($sql === false) {
        throw new RuntimeException('Failed to read migration file');
    }

    // Split by semicolons and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($stmt) => $stmt !== '' && !str_starts_with($stmt, '--')
    );

    $pdo->beginTransaction();
    
    $executed = 0;
    foreach ($statements as $statement) {
        if (trim($statement) === '') {
            continue;
        }

        try {
            $pdo->exec($statement);
            $executed++;
            Logger::info('Executed statement', ['statement' => substr($statement, 0, 100)]);
        } catch (\PDOException $e) {
            // Ignore duplicate key errors (index already exists)
            if ($e->getCode() !== '42000' && !str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
            Logger::info('Skipped existing index', ['error' => $e->getMessage()]);
        }
    }

    $pdo->commit();

    Logger::info('Performance migration 003 completed successfully', [
        'statements_executed' => $executed,
    ]);

    echo "✓ Performance migration 003 applied successfully\n";
    echo "  Statements executed: {$executed}\n";

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    Logger::error('Performance migration 003 failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    echo "✗ Migration failed: {$e->getMessage()}\n";
    exit(1);
}
