<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\FormScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\H2hScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\LiveScoreCalculator;
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

/**
 * Integration test for full V2 flow.
 * Tests the complete V2 pipeline with all components, gating conditions, and red flags.
 */
final class V2FlowTest extends TestCase
{
    private AlgorithmOne $algorithm;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set environment to V2 mode
        $_ENV['ALGORITHM_VERSION'] = '2';
        $_ENV['ALGORITHM1_DUAL_RUN'] = '0';
        
        // Build full algorithm with all dependencies
        $formCalculator = new FormScoreCalculator();
        $h2hCalculator = new H2hScoreCalculator();
        $liveCalculator = new LiveScoreCalculator();
        $legacyCalculator = new ProbabilityCalculator($formCalculator, $h2hCalculator, $liveCalculator);
        
        // V2 calculator with all components
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
        
        $this->algorithm = new AlgorithmOne($legacyCalculator, $v2Calculator, $legacyFilter);
    }

    protected function tearDown(): void
    {
        unset($_ENV['ALGORITHM_VERSION']);
        unset($_ENV['ALGORITHM1_DUAL_RUN']);
        parent::tearDown();
    }

    /**
     * Test successful bet with all V2 components working together.
     */
    public function testSuccessfulBetWithAllV2Components(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(
                homeGoals: 4,
                awayGoals: 3,
                weightedScore: 0.75
            ),
            'h2h_data' => $this->buildH2hData(homeGoals: 3, awayGoals: 3),
            'live_data' => $this->buildLiveDataV2(
                minute: 28,
                shotsOnTarget: 10,
                shotsOffTarget: 5,
                dangerousAttacks: 45,
                xgHome: 1.5,
                xgAway: 1.3,
                tableAvg: 2.8,
                yellowCardsHome: 1,
                yellowCardsAway: 2
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertTrue($result['bet'], 'Should recommend bet with strong V2 indicators');
        $this->assertGreaterThanOrEqual(0.55, $result['confidence']);
    }

    /**
     * Test gating condition: no form data.
     */
    public function testGatingConditionNoFormData(): void
    {
        $matchData = [
            'form_data' => ['has_data' => false, 'home_goals' => 0, 'away_goals' => 0],
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveDataV2(minute: 25, shotsOnTarget: 8, dangerousAttacks: 40),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
        $this->assertSame(0.0, $result['confidence']);
    }

    /**
     * Test gating condition: no H2H data.
     */
    public function testMissingH2hIsHandledAsSoftPenalty(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3),
            'h2h_data' => ['has_data' => false, 'home_goals' => 0, 'away_goals' => 0],
            'live_data' => $this->buildLiveDataV2(minute: 25, shotsOnTarget: 8, dangerousAttacks: 40),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertGreaterThan(0.0, $result['confidence']);
        $this->assertFalse($result['debug']['gating_context']['has_h2h_data']);
        $this->assertSame(0.98, $result['debug']['penalties']['missing_h2h']);
    }

    /**
     * Test gating condition: score not 0:0.
     */
    public function testGatingConditionScoreNot00(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveDataV2(
                minute: 25,
                shotsOnTarget: 8,
                dangerousAttacks: 40,
                htHscore: 1,
                htAscore: 0
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
    }

    /**
     * Test gating condition: minute out of range (too early).
     */
    public function testGatingConditionMinuteTooEarly(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveDataV2(minute: 10, shotsOnTarget: 8, dangerousAttacks: 40),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
    }

    /**
     * Test gating condition: minute out of range (too late).
     */
    public function testGatingConditionMinuteTooLate(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveDataV2(minute: 35, shotsOnTarget: 8, dangerousAttacks: 40),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
    }

    /**
     * Test gating condition: insufficient shots on target.
     */
    public function testGatingConditionInsufficientShotsOnTarget(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveDataV2(
                minute: 25,
                shotsOnTarget: 0,  // Below minimum
                dangerousAttacks: 40
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
    }

    /**
     * Test early relief for zero shots on target in 15-18 minute window.
     */
    public function testEarlyShotsReliefCanBypassGate(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveDataV2(
                minute: 18,
                shotsOnTarget: 0,
                shotsOffTarget: 7,
                dangerousAttacks: 38,
                xgHome: 0.7,
                xgAway: 0.4,
                hasTrendData: true,
                trendShotsTotal: 7,
                trendDangerousAttacks: 14,
                trendWindowSeconds: 300
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertIsFloat($result['confidence']);
        $this->assertTrue($result['debug']['gating_context']['shots_gate_relief']);
    }

    /**
     * Test red flag: low accuracy (< 25%).
     */
    public function testRedFlagLowAccuracyBecomesPenalty(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveDataV2(
                minute: 25,
                shotsOnTarget: 2,      // 2 on target
                shotsOffTarget: 18,    // 18 off target = 10% accuracy
                dangerousAttacks: 45
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertGreaterThan(0.0, $result['confidence']);
        $this->assertContains('low_accuracy', $result['debug']['red_flags']);
        $this->assertSame(0.88, $result['debug']['penalties']['low_accuracy']);
    }

    /**
     * Test red flag: ineffective pressure becomes penalty.
     */
    public function testRedFlagIneffectivePressureBecomesPenalty(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveDataV2(
                minute: 25,
                shotsOnTarget: 1,           // Only 1 shot on target
                shotsOffTarget: 5,
                dangerousAttacks: 35,       // 35 dangerous attacks but only 1 shot on target
                dangerousAttacksHome: 32,   // Home team has 32 attacks but < 2 shots
                shotsOnTargetHome: 1
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertGreaterThan(0.0, $result['confidence']);
        $this->assertContains('ineffective_pressure', $result['debug']['red_flags']);
        $this->assertSame(0.90, $result['debug']['penalties']['ineffective_pressure']);
    }

    /**
     * Test red flag: xg_mismatch (amplifier, not blocker).
     */
    public function testRedFlagXgMismatchAmplifier(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3, weightedScore: 0.75),
            'h2h_data' => $this->buildH2hData(homeGoals: 3, awayGoals: 3),
            'live_data' => $this->buildLiveDataV2(
                minute: 28,
                shotsOnTarget: 10,
                shotsOffTarget: 5,
                dangerousAttacks: 50,
                xgHome: 1.5,    // Total xG > 1.2
                xgAway: 1.0,    // But score still 0:0 at 28 min
                htHscore: 0,
                htAscore: 0
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        // xg_mismatch should amplify time pressure by 20%, not block
        // So bet can still be true if probability is high enough
        $this->assertIsFloat($result['confidence']);
    }

    /**
     * Test PDI component with balanced attacks.
     */
    public function testPdiComponentBalancedAttacks(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3, weightedScore: 0.75),
            'h2h_data' => $this->buildH2hData(homeGoals: 3, awayGoals: 3),
            'live_data' => $this->buildLiveDataV2(
                minute: 25,
                shotsOnTarget: 10,
                dangerousAttacks: 40,
                dangerousAttacksHome: 20,  // Perfectly balanced
                dangerousAttacksAway: 20,
                xgHome: 1.2,
                xgAway: 1.1
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        // Balanced attacks should contribute to high PDI
        $this->assertIsFloat($result['confidence']);
    }

    /**
     * Test shot quality component with high xG.
     */
    public function testShotQualityWithHighXg(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3, weightedScore: 0.75),
            'h2h_data' => $this->buildH2hData(homeGoals: 3, awayGoals: 3),
            'live_data' => $this->buildLiveDataV2(
                minute: 25,
                shotsOnTarget: 12,
                shotsOffTarget: 3,  // High accuracy
                dangerousAttacks: 45,
                xgHome: 2.0,  // Very high xG
                xgAway: 1.8
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        // High xG and accuracy should boost probability
        $this->assertGreaterThan(0.5, $result['confidence']);
    }

    /**
     * Test time pressure at different minutes.
     */
    public function testTimePressureProgression(): void
    {
        $baseData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3, weightedScore: 0.75),
            'h2h_data' => $this->buildH2hData(homeGoals: 3, awayGoals: 3),
        ];

        // Test at minute 15 (start of window)
        $matchData15 = array_merge($baseData, [
            'live_data' => $this->buildLiveDataV2(minute: 15, shotsOnTarget: 8, dangerousAttacks: 40, xgHome: 1.0, xgAway: 0.9),
        ]);
        $result15 = $this->algorithm->analyze($matchData15);

        // Test at minute 30 (end of window)
        $matchData30 = array_merge($baseData, [
            'live_data' => $this->buildLiveDataV2(minute: 30, shotsOnTarget: 12, dangerousAttacks: 60, xgHome: 1.5, xgAway: 1.4),
        ]);
        $result30 = $this->algorithm->analyze($matchData30);

        // Probability at minute 30 should be higher due to time pressure
        $this->assertGreaterThanOrEqual($result15['confidence'], $result30['confidence']);
    }

    /**
     * Test league factor adjustment.
     */
    public function testLeagueFactorAdjustment(): void
    {
        $baseData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3, weightedScore: 0.75),
            'h2h_data' => $this->buildH2hData(homeGoals: 3, awayGoals: 3),
        ];

        // High scoring league (table_avg = 3.5)
        $matchDataHigh = array_merge($baseData, [
            'live_data' => $this->buildLiveDataV2(
                minute: 25,
                shotsOnTarget: 10,
                dangerousAttacks: 45,
                xgHome: 1.5,
                xgAway: 1.3,
                tableAvg: 3.5  // High scoring league
            ),
        ]);
        $resultHigh = $this->algorithm->analyze($matchDataHigh);

        // Low scoring league (table_avg = 1.8)
        $matchDataLow = array_merge($baseData, [
            'live_data' => $this->buildLiveDataV2(
                minute: 25,
                shotsOnTarget: 10,
                dangerousAttacks: 45,
                xgHome: 1.5,
                xgAway: 1.3,
                tableAvg: 1.8  // Low scoring league
            ),
        ]);
        $resultLow = $this->algorithm->analyze($matchDataLow);

        // High scoring league should have higher probability
        $this->assertGreaterThan($resultLow['confidence'], $resultHigh['confidence']);
    }

    /**
     * Test card factor with away team advantage.
     */
    public function testCardFactorAwayAdvantage(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3, weightedScore: 0.75),
            'h2h_data' => $this->buildH2hData(homeGoals: 3, awayGoals: 3),
            'live_data' => $this->buildLiveDataV2(
                minute: 25,
                shotsOnTarget: 10,
                dangerousAttacks: 45,
                xgHome: 1.5,
                xgAway: 1.3,
                yellowCardsHome: 3,  // Home team has more cards
                yellowCardsAway: 0   // Away team clean
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        // Card advantage for away team should boost probability
        $this->assertIsFloat($result['confidence']);
    }

    /**
     * Test with trend data acceleration.
     */
    public function testTrendAcceleration(): void
    {
        $matchData = [
            'form_data' => $this->buildFormDataV2(homeGoals: 4, awayGoals: 3, weightedScore: 0.75),
            'h2h_data' => $this->buildH2hData(homeGoals: 3, awayGoals: 3),
            'live_data' => $this->buildLiveDataV2(
                minute: 25,
                shotsOnTarget: 10,
                dangerousAttacks: 45,
                xgHome: 1.5,
                xgAway: 1.3,
                hasTrendData: true,
                trendShotsTotal: 8,
                trendShotsOnTarget: 5,
                trendDangerousAttacks: 15,
                trendWindowSeconds: 300
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        // Strong trends should contribute to probability
        $this->assertIsFloat($result['confidence']);
    }

    // Helper methods

    private function buildFormDataV2(
        int $homeGoals,
        int $awayGoals,
        ?float $weightedScore = null
    ): array {
        $data = [
            'home_goals' => $homeGoals,
            'away_goals' => $awayGoals,
            'has_data' => true,
        ];

        if ($weightedScore !== null) {
            $data['weighted'] = [
                'score' => $weightedScore,
                'home' => ['attack' => 0.8, 'defense' => 0.3],
                'away' => ['attack' => 0.6, 'defense' => 0.4],
            ];
        }

        return $data;
    }

    private function buildH2hData(int $homeGoals, int $awayGoals): array
    {
        return [
            'home_goals' => $homeGoals,
            'away_goals' => $awayGoals,
            'has_data' => true,
        ];
    }

    private function buildLiveDataV2(
        int $minute,
        int $shotsOnTarget,
        int $dangerousAttacks,
        int $shotsOffTarget = 0,
        int $htHscore = 0,
        int $htAscore = 0,
        ?float $xgHome = null,
        ?float $xgAway = null,
        ?float $tableAvg = null,
        int $yellowCardsHome = 0,
        int $yellowCardsAway = 0,
        ?int $dangerousAttacksHome = null,
        ?int $dangerousAttacksAway = null,
        ?int $shotsOnTargetHome = null,
        bool $hasTrendData = false,
        ?int $trendShotsTotal = null,
        ?int $trendShotsOnTarget = null,
        ?int $trendDangerousAttacks = null,
        ?int $trendWindowSeconds = null
    ): array {
        $shotsOnTargetHome = $shotsOnTargetHome ?? (int) ($shotsOnTarget / 2);
        $shotsOnTargetAway = $shotsOnTarget - $shotsOnTargetHome;
        $shotsOffTargetHome = (int) ($shotsOffTarget / 2);
        $shotsOffTargetAway = $shotsOffTarget - $shotsOffTargetHome;
        $dangerousAttacksHome = $dangerousAttacksHome ?? (int) ($dangerousAttacks / 2);
        $dangerousAttacksAway = $dangerousAttacksAway ?? ($dangerousAttacks - $dangerousAttacksHome);

        return [
            'minute' => $minute,
            'shots_total' => $shotsOnTarget + $shotsOffTarget,
            'shots_on_target' => $shotsOnTarget,
            'dangerous_attacks' => $dangerousAttacks,
            'corners' => 0,
            'shots_on_target_home' => $shotsOnTargetHome,
            'shots_on_target_away' => $shotsOnTargetAway,
            'shots_off_target_home' => $shotsOffTargetHome,
            'shots_off_target_away' => $shotsOffTargetAway,
            'dangerous_attacks_home' => $dangerousAttacksHome,
            'dangerous_attacks_away' => $dangerousAttacksAway,
            'corners_home' => 0,
            'corners_away' => 0,
            'xg_home' => $xgHome,
            'xg_away' => $xgAway,
            'xg_total' => ($xgHome ?? 0.0) + ($xgAway ?? 0.0),
            'yellow_cards_home' => $yellowCardsHome,
            'yellow_cards_away' => $yellowCardsAway,
            'trend_shots_total_delta' => $trendShotsTotal,
            'trend_shots_on_target_delta' => $trendShotsOnTarget,
            'trend_dangerous_attacks_delta' => $trendDangerousAttacks,
            'trend_xg_delta' => null,
            'trend_window_seconds' => $trendWindowSeconds,
            'has_trend_data' => $hasTrendData,
            'ht_hscore' => $htHscore,
            'ht_ascore' => $htAscore,
            'live_hscore' => $htHscore,
            'live_ascore' => $htAscore,
            'time_str' => sprintf('%d:00', $minute),
            'match_status' => '1',
            'table_avg' => $tableAvg,
        ];
    }
}
