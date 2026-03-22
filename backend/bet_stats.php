<?php

declare(strict_types=1);

require_once __DIR__ . '/line/env.php';
require_once __DIR__ . '/line/logger.php';
require_once __DIR__ . '/line/Db.php';
require_once __DIR__ . '/scanner/BetMessageRepository.php';

use Proxbet\Line\Env;
use Proxbet\Line\Logger;
use Proxbet\Line\Db;
use Proxbet\Scanner\BetMessageRepository;

// Parse command line options
$options = getopt('', ['json', 'period:', 'recent:']);
$jsonOutput = isset($options['json']);
$period = isset($options['period']) ? (string) $options['period'] : 'all';
$recentLimit = isset($options['recent']) ? (int) $options['recent'] : 10;

// Load environment variables
Env::load(__DIR__ . '/../.env');
Logger::init();

try {
    // Connect to database
    $pdo = Db::connectFromEnv();
    $repository = new BetMessageRepository($pdo);

    // Get statistics
    $stats = $repository->getStatisticsByPeriod($period);
    $recentBets = $repository->getRecentBets($recentLimit);

    if ($jsonOutput) {
        // JSON output
        echo json_encode([
            'statistics' => $stats,
            'recent_bets' => $recentBets,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        // Formatted text output
        displayStatistics($stats, $recentBets);
    }

    exit(0);
} catch (\Throwable $e) {
    if ($jsonOutput) {
        echo json_encode([
            'error' => true,
            'message' => $e->getMessage(),
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "ERROR: {$e->getMessage()}\n";
    }
    exit(1);
}

/**
 * Display statistics in formatted text.
 *
 * @param array<string,mixed> $stats
 * @param array<int,array<string,mixed>> $recentBets
 */
function displayStatistics(array $stats, array $recentBets): void
{
    echo str_repeat('=', 80) . PHP_EOL;
    echo "СТАТИСТИКА СТАВОК" . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
    echo PHP_EOL;

    // Period
    $periodName = match ($stats['period']) {
        'today' => 'Сегодня',
        'week' => 'За неделю',
        'month' => 'За месяц',
        default => 'За все время',
    };
    echo "Период: {$periodName}" . PHP_EOL;
    echo PHP_EOL;

    // Overall statistics
    echo "📊 ОБЩАЯ СТАТИСТИКА" . PHP_EOL;
    echo str_repeat('-', 80) . PHP_EOL;
    echo sprintf("Всего ставок:      %d\n", $stats['total']);
    echo sprintf("Ожидают проверки:  %d\n", $stats['pending']);
    echo sprintf("Выиграно:          %d\n", $stats['won']);
    echo sprintf("Проиграно:         %d\n", $stats['lost']);
    echo PHP_EOL;

    // Win rate
    $completed = (int) ($stats['won'] ?? 0) + (int) ($stats['lost'] ?? 0);
    if ($completed > 0) {
        echo "📈 ПРОЦЕНТ УСПЕШНОСТИ" . PHP_EOL;
        echo str_repeat('-', 80) . PHP_EOL;
        echo sprintf("Процент выигрыша:  %.2f%% (%d из %d)\n", $stats['win_rate'], $stats['won'], $completed);
        echo sprintf("Процент проигрыша: %.2f%% (%d из %d)\n", $stats['loss_rate'], $stats['lost'], $completed);
        echo PHP_EOL;

        // Visual bar
        $winBarLength = (int) round($stats['win_rate'] / 2);
        $lossBarLength = (int) round($stats['loss_rate'] / 2);
        echo "Визуализация:" . PHP_EOL;
        echo "✅ " . str_repeat('█', $winBarLength) . " {$stats['won']}\n";
        echo "❌ " . str_repeat('█', $lossBarLength) . " {$stats['lost']}\n";
        echo PHP_EOL;
    }

    // Recent bets
    if (!empty($recentBets)) {
        echo "📋 ПОСЛЕДНИЕ СТАВКИ" . PHP_EOL;
        echo str_repeat('-', 80) . PHP_EOL;
        
        foreach ($recentBets as $bet) {
            $status = match ($bet['bet_status']) {
                'won' => '✅ ЗАШЛА',
                'lost' => '❌ НЕ ЗАШЛА',
                default => '⏳ ОЖИДАНИЕ',
            };
            
            $score = sprintf(
                '%d:%d',
                (int) ($bet['live_ht_hscore'] ?? 0),
                (int) ($bet['live_ht_ascore'] ?? 0)
            );
            
            $home = $bet['home'] ?? 'N/A';
            $away = $bet['away'] ?? 'N/A';
            $sentAt = date('d.m.Y H:i', strtotime($bet['sent_at']));
            
            echo sprintf(
                "%s | %s - %s | Счет: %s | %s\n",
                $status,
                $home,
                $away,
                $score,
                $sentAt
            );
        }
        echo PHP_EOL;
    }

    echo str_repeat('=', 80) . PHP_EOL;
}
