<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';

use Proxbet\Core\Services\BetCheckerService;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;
use Proxbet\Scanner\BetMessageRepository;

proxbet_bootstrap_env();
Logger::init();

Logger::info('Bet checker CLI started');

try {
    proxbet_require_env(['TELEGRAM_BOT_TOKEN']);
    $botToken = (string) getenv('TELEGRAM_BOT_TOKEN');
    
    $db = Db::connectFromEnv();
    $repository = new BetMessageRepository($db);
    $service = new BetCheckerService($repository, $botToken);
    
    // Check pending bets
    $result = $service->checkPendingBets();
    
    // Display statistics if any bets were processed
    if ($result['checked'] > 0) {
        $service->displayStatistics();
    }
    
    // Exit with error code if there were errors
    exit($result['errors'] > 0 ? 1 : 0);
} catch (\Throwable $e) {
    Logger::error('Bet checker failed', ['error' => $e->getMessage()]);
    exit(1);
}
