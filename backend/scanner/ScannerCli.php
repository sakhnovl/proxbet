<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../bootstrap/runtime.php';

use Proxbet\Core\Services\ScannerService;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;
use Proxbet\Scanner\Algorithms\AlgorithmOne;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator as LegacyProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ProbabilityCalculatorV2;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\PdiCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ShotQualityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TrendCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TimePressureCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\LeagueFactorCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\CardFactorCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\XgPressureCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\RedFlagChecker;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\DualRunService;
use Proxbet\Scanner\Algorithms\AlgorithmX\AlgorithmX;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\AisCalculator as AlgorithmXAisCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\InterpretationGenerator as AlgorithmXInterpretationGenerator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ModifierCalculator as AlgorithmXModifierCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ProbabilityCalculator as AlgorithmXProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Config as AlgorithmXConfig;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataExtractor as AlgorithmXDataExtractor;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataValidator as AlgorithmXDataValidator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Filters\DecisionFilter as AlgorithmXDecisionFilter;
use Proxbet\Scanner\AlgorithmOneChannelVerdictGenerator;
use Proxbet\Scanner\BetMessageRepository;
use Proxbet\Scanner\DataExtractor;
use Proxbet\Scanner\MatchFilter;
use Proxbet\Scanner\ProbabilityCalculator;
use Proxbet\Scanner\ResultFormatter;
use Proxbet\Scanner\ScannerOutput;
use Proxbet\Scanner\TelegramNotifier;
use Proxbet\Telegram\TelegramAiRepository;

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
    
    // Initialize AlgorithmOne components
    $legacyCalculator = new LegacyProbabilityCalculator();
    
    // Initialize V2 calculator dependencies
    $pdiCalculator = new PdiCalculator();
    $shotQualityCalculator = new ShotQualityCalculator();
    $trendCalculator = new TrendCalculator();
    $timePressureCalculator = new TimePressureCalculator();
    $leagueFactorCalculator = new LeagueFactorCalculator();
    $cardFactorCalculator = new CardFactorCalculator();
    $xgPressureCalculator = new XgPressureCalculator();
    $redFlagChecker = new RedFlagChecker();
    
    $v2Calculator = new ProbabilityCalculatorV2(
        $pdiCalculator,
        $shotQualityCalculator,
        $trendCalculator,
        $timePressureCalculator,
        $leagueFactorCalculator,
        $cardFactorCalculator,
        $xgPressureCalculator,
        $redFlagChecker
    );
    
    $legacyFilter = new LegacyFilter();
    $dualRunService = new DualRunService($legacyCalculator, $v2Calculator, $legacyFilter);
    $algorithmOne = new AlgorithmOne($legacyCalculator, $v2Calculator, $legacyFilter, $dualRunService);

    $algorithmX = new AlgorithmX(
        new AlgorithmXConfig(),
        new AlgorithmXDataExtractor(),
        new AlgorithmXDataValidator(),
        new AlgorithmXProbabilityCalculator(
            new AlgorithmXAisCalculator(),
            new AlgorithmXModifierCalculator(),
            new AlgorithmXInterpretationGenerator()
        ),
        new AlgorithmXDecisionFilter()
    );
    
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
            $telegramAiRepository = new TelegramAiRepository($db);
            $algorithmOneVerdictGenerator = new AlgorithmOneChannelVerdictGenerator($telegramAiRepository);
            $notifier = new TelegramNotifier(
                $token,
                $channelId,
                $statePath,
                $repository,
                $algorithmOneVerdictGenerator
            );
            Logger::info('Telegram notifier initialized', ['channel_id' => $channelId]);
        }
    }

    // Create service and scan
    $service = new ScannerService($extractor, $calculator, $filter, $formatter, $algorithmOne, $algorithmX, $notifier);
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
