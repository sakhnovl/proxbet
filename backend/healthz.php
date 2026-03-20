<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);

require_once $rootDir . '/backend/line/env.php';
require_once $rootDir . '/backend/line/db.php';

use Proxbet\Line\Db;
use Proxbet\Line\Env;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Env::load($rootDir . '/.env');

try {
    Db::connectFromEnv();

    echo json_encode([
        'ok' => true,
        'service' => 'backend',
        'time' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);

    echo json_encode([
        'ok' => false,
        'service' => 'backend',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

