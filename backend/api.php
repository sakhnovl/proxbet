<?php

declare(strict_types=1);

/**
 * Public API for Proxbet
 * Returns active matches with odds
 */

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/security/RateLimiter.php';
require_once __DIR__ . '/security/SecurityHeaders.php';
require_once __DIR__ . '/security/RequestValidator.php';

use Proxbet\Line\Db;
use Proxbet\Security\RateLimiter;
use Proxbet\Security\SecurityHeaders;
use Proxbet\Security\RequestValidator;

proxbet_bootstrap_env();

// Apply security headers
SecurityHeaders::apply(isApi: true);

// Validate request size
RequestValidator::validateRequestSize();

// CORS
header('Content-Type: application/json; charset=utf-8');
proxbetHandleCors(['GET', 'OPTIONS'], ['Content-Type']);

// Rate limiting
$rateLimiter = new RateLimiter(
    __DIR__ . '/../data/rate_limits',
    maxAttempts: 100,
    windowSeconds: 60
);

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!$rateLimiter->check('public_api:' . $clientIp)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['ok' => false, 'error' => 'Too many requests'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $pdo = Db::connectFromEnv();
    
    // Get active matches with LIMIT for DoS protection
    $stmt = $pdo->prepare(
        'SELECT `id`, `evid`, `country`, `liga`, `home`, `away`, `start_time`, `odds_1`, `odds_x`, `odds_2`
         FROM `matches`
         WHERE `start_time` > NOW()
         ORDER BY `start_time` ASC
         LIMIT 1000'
    );
    $stmt->execute();
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'data' => $matches,
        'count' => count($matches)
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    
} catch (\Throwable $e) {
    $sanitizedError = RequestValidator::sanitizeErrorMessage($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $sanitizedError
    ], JSON_THROW_ON_ERROR);
}
