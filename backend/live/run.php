<?php

declare(strict_types=1);

require_once __DIR__ . '/../line/env.php';
require_once __DIR__ . '/../line/logger.php';
require_once __DIR__ . '/../line/Db.php';
require_once __DIR__ . '/service.php';

use Proxbet\Line\Env;
use Proxbet\Line\Logger;
use Proxbet\Line\Db;
use Proxbet\Live\LiveService;

Env::load(__DIR__ . '/../../.env');
Logger::init();

try {
    $db = Db::connectFromEnv();
    LiveService::run($db);
} catch (Throwable $e) {
    Logger::error('Live parser failed', ['error' => $e->getMessage()]);
    exit(1);
}

exit(0);
