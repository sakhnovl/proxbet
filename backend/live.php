<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';

use Proxbet\Core\Services\LiveUpdateService;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;

proxbet_bootstrap_env();
Logger::init();

try {
    $db = Db::connectFromEnv();
    $service = new LiveUpdateService($db);
    
    // Run live updates
    $service->runLiveUpdates();
    
    // Force finish stale matches
    $service->forceFinishStaleMatches();
} catch (Throwable $e) {
    Logger::error('Live parser failed', ['error' => $e->getMessage()]);
    exit(1);
}

exit(0);
