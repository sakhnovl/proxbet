<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/bootstrap/autoload.php';

use Proxbet\Line\Db;
use Proxbet\Line\Env;
use Proxbet\Scanner\Algorithms\AlgorithmX\AlgorithmX;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\AisCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\InterpretationGenerator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ModifierCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Config;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataExtractor;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataValidator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Filters\DecisionFilter;

Env::load(__DIR__ . '/../.env');

$evid = $argv[1] ?? 'TEST_ALGX_001';
$db = Db::connectFromEnv();

$stmt = $db->prepare('SELECT * FROM `matches` WHERE `evid` = ? LIMIT 1');
$stmt->execute([$evid]);
$match = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($match)) {
    fwrite(STDERR, "Match not found for evid: {$evid}\n");
    exit(1);
}

$algorithm = new AlgorithmX(
    new Config(),
    new DataExtractor(),
    new DataValidator(),
    new ProbabilityCalculator(
        new AisCalculator(),
        new ModifierCalculator(),
        new InterpretationGenerator()
    ),
    new DecisionFilter()
);

$result = $algorithm->analyze($match);

echo json_encode(
    [
        'match' => [
            'id' => (int) ($match['id'] ?? 0),
            'evid' => $match['evid'] ?? null,
            'home' => $match['home'] ?? null,
            'away' => $match['away'] ?? null,
            'time' => $match['time'] ?? null,
            'match_status' => $match['match_status'] ?? null,
        ],
        'result' => $result,
    ],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
) . PHP_EOL;
