<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';

use Proxbet\Line\Db;
use Proxbet\Line\SchemaBootstrap;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

proxbet_bootstrap_env();

try {
    $pdo = Db::connectFromEnv();
    $missingTables = [];

    foreach (SchemaBootstrap::requiredTables() as $table) {
        if (SchemaBootstrap::getTableColumns($pdo, $table) === []) {
            $missingTables[] = $table;
        }
    }

    if ($missingTables !== []) {
        throw new RuntimeException('Missing required tables: ' . implode(', ', $missingTables));
    }

    echo json_encode([
        'ok' => true,
        'service' => 'backend',
        'time' => date(DATE_ATOM),
        'checks' => [
            'database' => 'ok',
            'schema' => 'ok',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);

    echo json_encode([
        'ok' => false,
        'service' => 'backend',
        'error' => $e->getMessage(),
        'checks' => [
            'database' => 'error',
            'schema' => 'error',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
