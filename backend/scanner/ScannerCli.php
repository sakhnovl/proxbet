<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../bootstrap/runtime.php';

use Proxbet\Core\Services\ScannerService;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;
use Proxbet\Scanner\BetMessageRepository;
use Proxbet\Scanner\DataExtractor;
use Proxbet\Scanner\MatchFilter;
use Proxbet\Scanner\ProbabilityCalculator;
use Proxbet\Scanner\ResultFormatter;
use Proxbet\Scanner\ScannerOutput;
use Proxbet\Scanner\TelegramNotifier;

/**
 * CLI interface for running the scanner.
 *
 * Usage:
 *   php ScannerCli.php
 *   php ScannerCli.php --json
 *   php ScannerCli.php --verbose
 *   php ScannerCli.php --json --verbose
 */

$options = getopt('', ['json', 'verbose', 'min-probability:', 'no-telegram']);
$jsonOutput = isset($options['json']);
$verbose = isset($options['verbose']);
$minProbability = isset($options['min-probability']) ? (float) $options['min-probability'] : null;
$noTelegram = isset($options['no-telegram']);

try {
    proxbet_bootstrap_env();
    proxbet_require_env(['DB_HOST', 'DB_USER', 'DB_NAME']);
    $db = Db::connectFromEnv();

    // Initialize components
    $extractor = new DataExtractor($db);
    $calculator = new ProbabilityCalculator();
    $filter = new MatchFilter(resolveMinProbability($minProbability));
    $formatter = new ResultFormatter();
    
    // Initialize Telegram notifier if enabled
    $notifier = null;
    if (!$noTelegram) {
        $token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
        $channelId = getenv('TELEGRAM_CHANNEL_ID') ?: '';

        if ($token !== '' && $channelId !== '') {
            $statePath = getenv('SCANNER_STATE_PATH') ?: (proxbet_root_dir() . '/data/scanner_state.json');
            $stateDir = dirname($statePath);
            if (!is_dir($stateDir)) {
                @mkdir($stateDir, 0777, true);
            }
            $repository = new BetMessageRepository($db);
            $notifier = new TelegramNotifier($token, $channelId, $statePath, $repository);
            Logger::info('Telegram notifier initialized', ['channel_id' => $channelId]);
        }
    }

    // Create service and scan
    $service = new ScannerService($extractor, $calculator, $filter, $formatter, $notifier);
    $result = $service->scanAndNotify();

    // Output results
    if ($jsonOutput) {
        ScannerOutput::json($result);
    } else {
        ScannerOutput::formatted($result, $verbose);
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
        ScannerOutput::json($error);
    } else {
        echo "ERROR: {$e->getMessage()}\n";
        echo "File: {$e->getFile()}:{$e->getLine()}\n";
    }

    exit(1);
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
