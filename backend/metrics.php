<?php

declare(strict_types=1);

/**
 * Prometheus metrics endpoint (Protected)
 * 
 * Exposes application metrics in Prometheus format.
 * Should be protected from public access via authentication or network restrictions.
 */

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';
require_once __DIR__ . '/bootstrap/http.php';
require_once __DIR__ . '/core/PrometheusMetrics.php';

use Proxbet\Core\PrometheusMetrics;

proxbet_bootstrap_http_endpoint(['GET'], ['Authorization'], 'text/plain; version=0.0.4; charset=utf-8', false);

// ── Authentication ─────────────────────────────────────────────────────────

$authEnabled = filter_var(getenv('METRICS_AUTH_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$secretToken = (string) (getenv('METRICS_SECRET_TOKEN') ?: '');

if ($authEnabled) {
    $providedToken = proxbet_extract_bearer_token();
    
    // Validate token
    if ($secretToken === '' || $providedToken === '' || !hash_equals($secretToken, $providedToken)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR);
        exit;
    }
}

// ── Export Metrics ─────────────────────────────────────────────────────────

try {
    $metrics = PrometheusMetrics::getInstance();
    echo $metrics->export();
} catch (\Throwable $e) {
    http_response_code(500);
    echo "# Error exporting metrics\n";
}
