<?php

declare(strict_types=1);

/**
 * Prometheus metrics endpoint
 * Exposes application metrics in Prometheus format
 */

require_once __DIR__ . '/core/PrometheusMetrics.php';

use Proxbet\Core\PrometheusMetrics;

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');

$metrics = PrometheusMetrics::getInstance();
echo $metrics->export();
