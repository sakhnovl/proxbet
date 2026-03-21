<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\FormScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\H2hScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\LiveScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\CardFactorCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\LeagueFactorCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\PdiCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ProbabilityCalculatorV2;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\RedFlagChecker;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ShotQualityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TimePressureCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TrendCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\XgPressureCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\DualRunService;
use Psr\Log\LoggerInterface;

/**
 * Integration test for dual-run mode.
 */
final class DualRunTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['ALGORITHM_VERSION']);
        unset($_ENV['ALGORITHM1_DUAL_RUN']);

        parent::tearDown();
    }

    public function testDualRunReturnsComparisonForLegacyPrimary(): void
    {
        $_ENV['ALGORITHM_VERSION'] = '1';
        $_ENV['ALGORITHM1_DUAL_RUN'] = '1';

        $algorithm = $this->createAlgorithm();
        $result = $algorithm->analyze($this->buildStrongMatchData());

        $this->assertArrayHasKey('dual_run', $result);
        $this->assertSame(1, $result['dual_run']['primary_version']);
        $this->assertArrayHasKey('legacy_probability', $result['dual_run']);
        $this->assertArrayHasKey('v2_probability', $result['dual_run']);
        $this->assertArrayHasKey('probability_diff', $result['dual_run']);
        $this->assertArrayHasKey('decision_match', $result['dual_run']);
        $this->assertArrayHasKey('divergence_level', $result['dual_run']);
        $this->assertSame($result['dual_run']['legacy_bet'], $result['bet']);
        $this->assertSame($result['dual_run']['legacy_probability'], $result['confidence']);
        $this->assertGreaterThanOrEqual(0.0, $result['dual_run']['probability_diff']);
    }

    public function testDualRunReturnsComparisonForV2Primary(): void
    {
        $_ENV['ALGORITHM_VERSION'] = '2';
        $_ENV['ALGORITHM1_DUAL_RUN'] = '1';

        $algorithm = $this->createAlgorithm();
        $result = $algorithm->analyze($this->buildStrongMatchData());

        $this->assertArrayHasKey('dual_run', $result);
        $this->assertSame(2, $result['dual_run']['primary_version']);
        $this->assertSame($result['dual_run']['v2_bet'], $result['bet']);
        $this->assertSame($result['dual_run']['v2_probability'], $result['confidence']);
    }

    public function testDualRunLogsDivergenceWhenVersionsDisagree(): void
    {
        $_ENV['ALGORITHM_VERSION'] = '2';
        $_ENV['ALGORITHM1_DUAL_RUN'] = '1';

        $logger = new InMemoryLogger();
        $algorithm = $this->createAlgorithm($logger);
        $result = $algorithm->analyze($this->buildDivergentMatchData());

        $this->assertArrayHasKey('dual_run', $result);
        $this->assertFalse($result['dual_run']['decision_match']);
        $this->assertSame('high', $result['dual_run']['divergence_level']);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('Algorithm 1 dual-run divergence detected', $logger->records[0]['message']);
        $this->assertSame('high', $logger->records[0]['context']['divergence_level']);
        $this->assertFalse($logger->records[0]['context']['decision_match']);
    }

    private function createAlgorithm(?InMemoryLogger $logger = null): AlgorithmOne
    {
        $formCalculator = new FormScoreCalculator();
        $h2hCalculator = new H2hScoreCalculator();
        $liveCalculator = new LiveScoreCalculator();
        $legacyCalculator = new ProbabilityCalculator($formCalculator, $h2hCalculator, $liveCalculator);
        $v2Calculator = new ProbabilityCalculatorV2(
            new PdiCalculator(),
            new ShotQualityCalculator(),
            new TrendCalculator(),
            new TimePressureCalculator(),
            new LeagueFactorCalculator(),
            new CardFactorCalculator(),
            new XgPressureCalculator(),
            new RedFlagChecker()
        );
        $legacyFilter = new LegacyFilter();
        $dualRunService = new DualRunService(
            $legacyCalculator,
            $v2Calculator,
            $legacyFilter,
            $logger
        );

        return new AlgorithmOne($legacyCalculator, $v2Calculator, $legacyFilter, $dualRunService);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStrongMatchData(): array
    {
        return [
            'form_data' => [
                'home_goals' => 4,
                'away_goals' => 3,
                'has_data' => true,
                'weighted' => [
                    'score' => 0.78,
                    'home' => ['attack' => 0.84, 'defense' => 0.32],
                    'away' => ['attack' => 0.62, 'defense' => 0.44],
                ],
            ],
            'h2h_data' => [
                'home_goals' => 3,
                'away_goals' => 3,
                'has_data' => true,
            ],
            'live_data' => $this->buildLiveData(
                minute: 28,
                shotsOnTarget: 10,
                shotsOffTarget: 4,
                dangerousAttacks: 46,
                dangerousAttacksHome: 24,
                dangerousAttacksAway: 22,
                xgHome: 1.4,
                xgAway: 1.1,
                tableAvg: 2.9,
                yellowCardsHome: 1,
                yellowCardsAway: 2
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDivergentMatchData(): array
    {
        return [
            'form_data' => [
                'home_goals' => 4,
                'away_goals' => 3,
                'has_data' => true,
                'weighted' => [
                    'score' => 0.82,
                    'home' => ['attack' => 0.9, 'defense' => 0.3],
                    'away' => ['attack' => 0.7, 'defense' => 0.35],
                ],
            ],
            'h2h_data' => [
                'home_goals' => 3,
                'away_goals' => 2,
                'has_data' => true,
            ],
            'live_data' => $this->buildLiveData(
                minute: 25,
                shotsOnTarget: 1,
                shotsOffTarget: 5,
                dangerousAttacks: 42,
                dangerousAttacksHome: 32,
                dangerousAttacksAway: 10,
                xgHome: 0.9,
                xgAway: 0.3,
                tableAvg: 2.4,
                yellowCardsHome: 0,
                yellowCardsAway: 0,
                shotsOnTargetHome: 1
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLiveData(
        int $minute,
        int $shotsOnTarget,
        int $dangerousAttacks,
        int $shotsOffTarget = 0,
        int $dangerousAttacksHome = 0,
        int $dangerousAttacksAway = 0,
        ?float $xgHome = null,
        ?float $xgAway = null,
        ?float $tableAvg = null,
        int $yellowCardsHome = 0,
        int $yellowCardsAway = 0,
        int $shotsOnTargetHome = 0
    ): array {
        if ($dangerousAttacksHome === 0 && $dangerousAttacksAway === 0) {
            $dangerousAttacksHome = (int) floor($dangerousAttacks / 2);
            $dangerousAttacksAway = $dangerousAttacks - $dangerousAttacksHome;
        }

        if ($shotsOnTargetHome === 0 && $shotsOnTarget > 0) {
            $shotsOnTargetHome = (int) floor($shotsOnTarget / 2);
        }

        $shotsOnTargetAway = $shotsOnTarget - $shotsOnTargetHome;
        $shotsOffTargetHome = (int) floor($shotsOffTarget / 2);
        $shotsOffTargetAway = $shotsOffTarget - $shotsOffTargetHome;

        return [
            'minute' => $minute,
            'shots_total' => $shotsOnTarget + $shotsOffTarget,
            'shots_on_target' => $shotsOnTarget,
            'dangerous_attacks' => $dangerousAttacks,
            'corners' => 4,
            'shots_on_target_home' => $shotsOnTargetHome,
            'shots_on_target_away' => $shotsOnTargetAway,
            'shots_off_target_home' => $shotsOffTargetHome,
            'shots_off_target_away' => $shotsOffTargetAway,
            'dangerous_attacks_home' => $dangerousAttacksHome,
            'dangerous_attacks_away' => $dangerousAttacksAway,
            'corners_home' => 2,
            'corners_away' => 2,
            'xg_home' => $xgHome,
            'xg_away' => $xgAway,
            'xg_total' => ($xgHome ?? 0.0) + ($xgAway ?? 0.0),
            'yellow_cards_home' => $yellowCardsHome,
            'yellow_cards_away' => $yellowCardsAway,
            'trend_shots_total_delta' => 6,
            'trend_shots_on_target_delta' => 3,
            'trend_dangerous_attacks_delta' => 12,
            'trend_xg_delta' => 0.3,
            'trend_window_seconds' => 300,
            'has_trend_data' => true,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'time_str' => sprintf('%d:00', $minute),
            'match_status' => '1',
            'table_avg' => $tableAvg,
        ];
    }
}

final class InMemoryLogger implements LoggerInterface
{
    /**
     * @var list<array{level:string,message:string,context:array<string,mixed>}>
     */
    public array $records = [];

    /**
     * @param mixed $level
     * @param mixed $message
     * @param array<string,mixed> $context
     */
    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
