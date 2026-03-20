<?php

declare(strict_types=1);

require_once __DIR__ . '/../line/db.php';
require_once __DIR__ . '/../line/logger.php';
require_once __DIR__ . '/DataExtractor.php';
require_once __DIR__ . '/ProbabilityCalculator.php';
require_once __DIR__ . '/MatchFilter.php';
require_once __DIR__ . '/Scanner.php';
require_once __DIR__ . '/BetMessageRepository.php';
require_once __DIR__ . '/TelegramNotifier.php';

use Proxbet\Line\Db;
use Proxbet\Line\Logger;
use Proxbet\Scanner\DataExtractor;
use Proxbet\Scanner\ProbabilityCalculator;
use Proxbet\Scanner\MatchFilter;
use Proxbet\Scanner\Scanner;
use Proxbet\Scanner\BetMessageRepository;
use Proxbet\Scanner\TelegramNotifier;

/**
 * CLI interface for running the first half goal probability scanner.
 *
 * Usage:
 *   php ScannerCli.php
 *   php ScannerCli.php --json
 *   php ScannerCli.php --verbose
 *   php ScannerCli.php --json --verbose
 */

// Parse command line options
$options = getopt('', ['json', 'verbose', 'min-probability:', 'no-telegram']);
$jsonOutput = isset($options['json']);
$verbose = isset($options['verbose']);
$minProbability = isset($options['min-probability']) ? (float) $options['min-probability'] : null;
$noTelegram = isset($options['no-telegram']);

try {
    // Load environment variables
    $envFile = __DIR__ . '/../../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if ($key !== '' && !array_key_exists($key, $_ENV)) {
                        putenv("$key=$value");
                        $_ENV[$key] = $value;
                    }
                }
            }
        }
    }

    // Connect to database
    $db = Db::connectFromEnv();

    // Initialize scanner components
    $extractor = new DataExtractor($db);
    $calculator = new ProbabilityCalculator();
    $filter = new MatchFilter(resolveMinProbability($minProbability));
    $scanner = new Scanner($extractor, $calculator, $filter);

    // Initialize Telegram notifier if enabled
    $notifier = null;
    if (!$noTelegram) {
        $token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
        $channelId = getenv('TELEGRAM_CHANNEL_ID') ?: '';
        
        if ($token !== '' && $channelId !== '') {
            $statePath = getenv('SCANNER_STATE_PATH') ?: (__DIR__ . '/scanner_state.json');
            $stateDir = dirname($statePath);
            if (!is_dir($stateDir)) {
                @mkdir($stateDir, 0777, true);
            }
            $repository = new BetMessageRepository($db);
            $notifier = new TelegramNotifier($token, $channelId, $statePath, $repository);
            Logger::info('Telegram notifier initialized', ['channel_id' => $channelId]);
        }
    }

    // Run scanner
    $result = $scanner->scan();

    // Send Telegram notifications for signals
    if ($notifier !== null && !empty($result['results'])) {
        foreach ($result['results'] as $match) {
            if ($match['decision']['bet']) {
                $notifier->notifySignal($match);
            }
        }
    }

    // Output results
    if ($jsonOutput) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        outputFormatted($result, $verbose);
    }

    exit(0);
} catch (\Throwable $e) {
    $error = [
        'error' => true,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];

    if ($jsonOutput) {
        echo json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo "ERROR: {$e->getMessage()}\n";
        echo "File: {$e->getFile()}:{$e->getLine()}\n";
    }

    exit(1);
}

/**
 * Output results in formatted text.
 *
 * @param array{total:int,analyzed:int,signals:int,results:array<int,array<string,mixed>>} $result
 */
function outputFormatted(array $result, bool $verbose): void
{
    echo str_repeat('=', 80) . PHP_EOL;
    echo "СКАНЕР ВЕРОЯТНОСТИ ГОЛА В ПЕРВОМ ТАЙМЕ" . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
    echo PHP_EOL;

    echo "Всего матчей: {$result['total']}" . PHP_EOL;
    echo "Проанализировано: {$result['analyzed']}" . PHP_EOL;
    echo "Сигналов на ставку: {$result['signals']}" . PHP_EOL;
    echo PHP_EOL;

    if (empty($result['results'])) {
        echo "Нет активных матчей для анализа." . PHP_EOL;
        return;
    }

    // Separate signals and non-signals
    $signals = [];
    $others = [];

    foreach ($result['results'] as $match) {
        if ($match['decision']['bet']) {
            $signals[] = $match;
        } else {
            $others[] = $match;
        }
    }

    // Display signals first
    if (!empty($signals)) {
        echo str_repeat('=', 80) . PHP_EOL;
        echo "🔥 СИГНАЛЫ НА СТАВКУ ({$result['signals']})" . PHP_EOL;
        echo str_repeat('=', 80) . PHP_EOL;
        echo PHP_EOL;

        foreach ($signals as $match) {
            displayMatch($match, true);
        }
    }

    // Display other matches if verbose
    if ($verbose && !empty($others)) {
        echo str_repeat('=', 80) . PHP_EOL;
        echo "📊 ОСТАЛЬНЫЕ МАТЧИ (" . count($others) . ")" . PHP_EOL;
        echo str_repeat('=', 80) . PHP_EOL;
        echo PHP_EOL;

        foreach ($others as $match) {
            displayMatch($match, false);
        }
    }
}

function resolveMinProbability(?float $minProbability): float
{
    if ($minProbability === null) {
        return 0.65;
    }

    if ($minProbability < 0.0 || $minProbability > 1.0) {
        throw new InvalidArgumentException('--min-probability must be between 0 and 1');
    }

    return $minProbability;
}

/**
 * Display a single match.
 *
 * @param array<string,mixed> $match
 */
function displayMatch(array $match, bool $isSignal): void
{
    $icon = $isSignal ? '✅' : '❌';
    $probability = sprintf('%.0f%%', $match['probability'] * 100);

    echo "{$icon} [{$match['time']}] {$match['home']} - {$match['away']}" . PHP_EOL;
    echo "   {$match['country']} / {$match['liga']}" . PHP_EOL;
    echo "   Вероятность: {$probability} (форма: " . sprintf('%.2f', $match['form_score']) . 
         ", H2H: " . sprintf('%.2f', $match['h2h_score']) . 
         ", live: " . sprintf('%.2f', $match['live_score']) . ")" . PHP_EOL;
    echo "   Статистика: удары {$match['stats']['shots_total']} (в створ {$match['stats']['shots_on_target']}), " .
         "опасные атаки {$match['stats']['dangerous_attacks']}, угловые {$match['stats']['corners']}" . PHP_EOL;
    echo "   Форма 1T: дома {$match['form_data']['home_goals']}/5, гости {$match['form_data']['away_goals']}/5" . PHP_EOL;
    echo "   H2H 1T: дома {$match['h2h_data']['home_goals']}/5, гости {$match['h2h_data']['away_goals']}/5" . PHP_EOL;
    echo "   Решение: {$match['decision']['reason']}" . PHP_EOL;
    echo PHP_EOL;
}
