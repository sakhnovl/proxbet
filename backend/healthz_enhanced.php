<?php

declare(strict_types=1);

/**
 * Enhanced Health Check Endpoint (Protected)
 * 
 * This endpoint provides detailed system diagnostics and should be protected.
 * Authentication can be enabled via HEALTH_AUTH_ENABLED environment variable.
 */

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';
require_once __DIR__ . '/bootstrap/http.php';
require_once __DIR__ . '/security/HealthEndpointAuth.php';

use Proxbet\Core\HealthCheck;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;
use Proxbet\Security\HealthEndpointAuth;

proxbet_bootstrap_http_endpoint(['GET'], ['Authorization'], 'application/json; charset=utf-8', false);

// ── Authentication ─────────────────────────────────────────────────────────

$authEnabled = filter_var(getenv('HEALTH_AUTH_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$authUsername = (string) (getenv('HEALTH_AUTH_USERNAME') ?: '');
$authPassword = (string) (getenv('HEALTH_AUTH_PASSWORD') ?: '');
$allowedIPs = array_filter(array_map('trim', explode(',', (string) (getenv('HEALTH_ALLOWED_IPS') ?: ''))));

$auth = new HealthEndpointAuth(
    requireAuth: $authEnabled,
    username: $authUsername,
    password: $authPassword,
    allowedIPs: $allowedIPs
);

if (!$auth->validateAccess()) {
    // validateAccess() already sent the response
    exit;
}

// ── Health Checks ──────────────────────────────────────────────────────────

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
    
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'unhealthy',
        'message' => 'Health check failed',
        'timestamp' => time(),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
}
