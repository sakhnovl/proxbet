<?php

declare(strict_types=1);

require_once __DIR__ . '/line/env.php';
require_once __DIR__ . '/line/logger.php';
require_once __DIR__ . '/line/db.php';
require_once __DIR__ . '/scanner/BetMessageRepository.php';
require_once __DIR__ . '/scanner/BetChecker.php';

use Proxbet\Line\Env;
use Proxbet\Line\Logger;
use Proxbet\Line\Db;
use Proxbet\Scanner\BetMessageRepository;
use Proxbet\Scanner\BetChecker;

// Load environment variables
Env::load(__DIR__ . '/../.env');
Logger::init();

Logger::info('Bet checker CLI started');

// Get Telegram bot token
$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
if ($botToken === '') {
    Logger::error('TELEGRAM_BOT_TOKEN is not set in .env');
    exit(1);
}

// Connect to database
try {
    $pdo = Db::connectFromEnv();
    Logger::info('Database connected');
} catch (\Throwable $e) {
    Logger::error('Failed to connect to database', ['error' => $e->getMessage()]);
    exit(1);
}

// Initialize repository and checker
try {
    $repository = new BetMessageRepository($pdo);
    $checker = new BetChecker($repository, $botToken);
    
    // Check pending bets
    $result = $checker->checkPendingBets();
    
    Logger::info('Bet checker finished', [
        'checked' => $result['checked'],
        'won' => $result['won'],
        'lost' => $result['lost'],
        'pending' => $result['pending'],
        'errors' => $result['errors'],
    ]);
    
    // Display statistics if any bets were processed
    if ($result['checked'] > 0) {
        $stats = $repository->getStatistics();
        
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "СТАТИСТИКА СТАВОК\n";
        echo str_repeat('=', 60) . "\n";
        echo sprintf("Всего ставок: %d\n", $stats['total']);
        echo sprintf("Ожидают: %d | Выиграно: %d | Проиграно: %d\n", 
            $stats['pending'], $stats['won'], $stats['lost']);
        
        $completed = $stats['won'] + $stats['lost'];
        if ($completed > 0) {
            echo sprintf("Процент выигрыша: %.2f%% (%d из %d)\n", 
                $stats['win_rate'], $stats['won'], $completed);
        }
        echo str_repeat('=', 60) . "\n\n";
    }
    
    // Exit with error code if there were errors
    if ($result['errors'] > 0) {
        exit(1);
    }
    
    exit(0);
} catch (\Throwable $e) {
    Logger::error('Bet checker failed', ['error' => $e->getMessage()]);
    exit(1);
}
