<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';

use Proxbet\Core\Services\ParserService;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;

proxbet_bootstrap_env();
Logger::init();

try {
    proxbet_require_env(['API_URL']);
    $apiUrl = (string) getenv('API_URL');
    
    $db = Db::connectFromEnv();
    $service = new ParserService($db);
    
    // Fetch matches from API
    $matches = $service->fetchMatches($apiUrl);
    
    // Filter by bans
    $result = $service->filterByBans($matches);
    
    // Save to database
    $stats = $service->saveMatches($result['filtered']);
    $stats['banned'] = $result['banned'];
    
    Logger::info('DB upsert finished', $stats);
} catch (Throwable $e) {
    Logger::error('Parser failed', ['error' => $e->getMessage()]);
    exit(1);
}

exit(0);
