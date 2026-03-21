<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';

use Proxbet\Core\HealthCheck;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;

proxbet_bootstrap_env();

header('Content-Type: application/json');

try {
    $healthCheck = new HealthCheck();
    
    // Add database check
    try {
        $db = Db::connectFromEnv();
        $healthCheck->addDatabaseCheck($db);
    } catch (\Throwable $e) {
        Logger::error('Health check: Database connection failed', ['error' => $e->getMessage()]);
    }
    
    // Add Redis check if configured
    $redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
    $redisPort = (int) (getenv('REDIS_PORT') ?: 6379);
    $healthCheck->addRedisCheck($redisHost, $redisPort);
    
    // Add disk space check
    $healthCheck->addDiskSpaceCheck(__DIR__, 10);
    
    // Add memory check
    $healthCheck->addMemoryCheck(90);
    
    // Run all checks
    $result = $healthCheck->run();
    
    // Set HTTP status code based on health
    http_response_code($result['status'] === 'healthy' ? 200 : 503);
    
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'unhealthy',
        'message' => 'Health check failed: ' . $e->getMessage(),
        'timestamp' => time(),
    ], JSON_PRETTY_PRINT);
}
