<?php

declare(strict_types=1);

/**
 * Basic Health Check Endpoint (Public)
 * 
 * This endpoint provides minimal liveness check without exposing sensitive information.
 * For detailed diagnostics, use healthz_enhanced.php with authentication.
 */

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';
require_once __DIR__ . '/bootstrap/http.php';

use Proxbet\Line\Db;

proxbet_bootstrap_http_endpoint(['GET'], ['Content-Type']);

try {
    // Minimal database connectivity check
    $pdo = Db::connectFromEnv();
    $pdo->query('SELECT 1');

    // Return minimal success response
    echo json_encode([
        'status' => 'ok',
        'service' => 'proxbet-backend',
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);

    // Return minimal error response without exposing details
    echo json_encode([
        'status' => 'error',
        'service' => 'proxbet-backend',
    ], JSON_THROW_ON_ERROR);
}
