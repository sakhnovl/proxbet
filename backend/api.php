<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/api/support.php';

use Proxbet\Line\Db;

proxbet_bootstrap_env();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
proxbetHandleCors(['GET', 'OPTIONS'], ['Content-Type']);

try {
    proxbet_api_require_env();
    $pdo = Db::connectFromEnv();
} catch (\Throwable $e) {
    proxbet_api_json_error('Database connection failed: ' . $e->getMessage(), 503);
}

proxbet_api_handle_request($pdo, $_GET, $_SERVER);
