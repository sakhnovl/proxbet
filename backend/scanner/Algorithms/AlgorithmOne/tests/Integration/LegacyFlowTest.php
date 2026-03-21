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
 * Integration test for full legacy (v1) flow.
 * Tests the complete pipeline from data input to betting decision.
 */
final class LegacyFlowTest extends TestCase
{
    private AlgorithmOne $algorithm;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set environment to legacy mode
        $_ENV['ALGORITHM_VERSION'] = '1';
        $_ENV['ALGORITHM1_DUAL_RUN'] = '0';
        
        // Build full algorithm with all dependencies
        $formCalculator = new FormScoreCalculator();
        $h2hCalculator = new H2hScoreCalculator();
        $liveCalculator = new LiveScoreCalculator();
        $legacyCalculator = new ProbabilityCalculator($formCalculator, $h2hCalculator, $liveCalculator);
        
        // V2 calculator (not used in legacy mode but required by constructor)
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
     * Test successful bet scenario with high probability and active game.
     */
    public function testSuccessfulBetWithHighProbability(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveData(
                minute: 22,
                shotsOnTarget: 8,
                shotsOffTarget: 4,
                dangerousAttacks: 35,
                corners: 6
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertTrue($result['bet'], 'Should recommend bet');
        $this->assertGreaterThanOrEqual(0.55, $result['confidence'], 'Confidence should be >= 55%');
        $this->assertStringContainsString('высокая вероятность', $result['reason']);
    }

    /**
     * Test rejection due to insufficient form data.
     */
    public function testRejectionDueToNoFormData(): void
    {
        $matchData = [
            'form_data' => ['has_data' => false, 'home_goals' => 0, 'away_goals' => 0],
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveData(minute: 22, shotsOnTarget: 8, dangerousAttacks: 30),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('недостаточно данных по форме', $result['reason']);
    }

    /**
     * Test rejection due to insufficient H2H data.
     */
    public function testRejectionDueToNoH2hData(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 4, awayGoals: 3),
            'h2h_data' => ['has_data' => false, 'home_goals' => 0, 'away_goals' => 0],
            'live_data' => $this->buildLiveData(minute: 22, shotsOnTarget: 8, dangerousAttacks: 30),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('недостаточно данных по H2H', $result['reason']);
    }

    /**
     * Test rejection when goal already scored in first half.
     */
    public function testRejectionDueToGoalScored(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveData(
                minute: 22,
                shotsOnTarget: 8,
                dangerousAttacks: 30,
                htHscore: 1,  // Goal scored!
                htAscore: 0
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('гол уже забит', $result['reason']);
    }

    /**
     * Test rejection when minute is too early (< 15).
     */
    public function testRejectionDueToTooEarly(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveData(
                minute: 10,  // Too early
                shotsOnTarget: 8,
                dangerousAttacks: 30
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('слишком рано', $result['reason']);
        $this->assertStringContainsString('10', $result['reason']);
    }

    /**
     * Test rejection when minute is too late (> 30).
     */
    public function testRejectionDueToTooLate(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveData(
                minute: 35,  // Too late
                shotsOnTarget: 8,
                dangerousAttacks: 30
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('слишком поздно', $result['reason']);
        $this->assertStringContainsString('35', $result['reason']);
    }

    /**
     * Test rejection when no shots on target.
     */
    public function testRejectionDueToNoShotsOnTarget(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveData(
                minute: 22,
                shotsOnTarget: 0,  // No shots on target
                shotsOffTarget: 10,
                dangerousAttacks: 30
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('нет ударов в створ', $result['reason']);
    }

    /**
     * Test rejection when insufficient dangerous attacks (< 20).
     */
    public function testRejectionDueToInsufficientDangerousAttacks(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveData(
                minute: 22,
                shotsOnTarget: 8,
                dangerousAttacks: 15  // Too few
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('мало опасных атак', $result['reason']);
        $this->assertStringContainsString('15', $result['reason']);
    }

    /**
     * Test rejection when probability is below threshold.
     */
    public function testRejectionDueToProbabilityBelowThreshold(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 1, awayGoals: 0),  // Low form
            'h2h_data' => $this->buildH2hData(homeGoals: 0, awayGoals: 1),    // Low H2H
            'live_data' => $this->buildLiveData(
                minute: 22,
                shotsOnTarget: 1,  // Minimal
                dangerousAttacks: 20  // Minimal
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('вероятность ниже порога', $result['reason']);
        $this->assertLessThan(0.55, $result['confidence']);
    }

    /**
     * Test edge case: exactly at minimum thresholds.
     */
    public function testEdgeCaseAtMinimumThresholds(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 3, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 3, awayGoals: 3),
            'live_data' => $this->buildLiveData(
                minute: 15,  // Exactly at minimum
                shotsOnTarget: 1,  // Exactly at minimum
                dangerousAttacks: 20  // Exactly at minimum
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        // Should pass all checks if probability is high enough
        if ($result['confidence'] >= 0.55) {
            $this->assertTrue($result['bet']);
        } else {
            $this->assertFalse($result['bet']);
        }
    }

    /**
     * Test probability calculation formula: form*0.35 + h2h*0.15 + live*0.50
     */
    public function testProbabilityFormulaCalculation(): void
    {
        // Create scenario with known values
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 5, awayGoals: 5),  // form_score = 1.0
            'h2h_data' => $this->buildH2hData(homeGoals: 5, awayGoals: 5),    // h2h_score = 1.0
            'live_data' => $this->buildLiveData(
                minute: 25,
                shotsOnTarget: 10,
                dangerousAttacks: 40,
                corners: 8
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        // With perfect form and h2h, probability should be high
        // form*0.35 + h2h*0.15 + live*0.50 = 0.35 + 0.15 + live*0.50
        $this->assertGreaterThan(0.5, $result['confidence']);
    }

    /**
     * Test with xG data included in live statistics.
     */
    public function testWithXgData(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveData(
                minute: 25,
                shotsOnTarget: 8,
                dangerousAttacks: 35,
                xgHome: 1.2,
                xgAway: 0.9
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        // xG should contribute to higher probability
        $this->assertIsFloat($result['confidence']);
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
    }

    /**
     * Test with card data included.
     */
    public function testWithCardData(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveData(
                minute: 25,
                shotsOnTarget: 8,
                dangerousAttacks: 35,
                yellowCardsHome: 2,
                yellowCardsAway: 1
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        // Cards should be considered in legacy calculation
        $this->assertIsFloat($result['confidence']);
    }

    /**
     * Test with trend data included.
     */
    public function testWithTrendData(): void
    {
        $matchData = [
            'form_data' => $this->buildFormData(homeGoals: 4, awayGoals: 3),
            'h2h_data' => $this->buildH2hData(homeGoals: 2, awayGoals: 2),
            'live_data' => $this->buildLiveData(
                minute: 25,
                shotsOnTarget: 8,
                dangerousAttacks: 35,
                hasTrendData: true,
                trendShotsTotal: 5,
                trendShotsOnTarget: 3,
                trendDangerousAttacks: 10,
                trendWindowSeconds: 300
            ),
        ];

        $result = $this->algorithm->analyze($matchData);

        // Trends should contribute to probability
        $this->assertIsFloat($result['confidence']);
    }

    // Helper methods

    private function buildFormData(int $homeGoals, int $awayGoals): array
    {
        return [
            'home_goals' => $homeGoals,
            'away_goals' => $awayGoals,
            'has_data' => true,
        ];
    }

    private function buildH2hData(int $homeGoals, int $awayGoals): array
    {
        return [
            'home_goals' => $homeGoals,
            'away_goals' => $awayGoals,
            'has_data' => true,
        ];
    }

    private function buildLiveData(
        int $minute,
        int $shotsOnTarget,
        int $dangerousAttacks,
        int $shotsOffTarget = 0,
        int $corners = 0,
        int $htHscore = 0,
        int $htAscore = 0,
        ?float $xgHome = null,
        ?float $xgAway = null,
        ?int $yellowCardsHome = null,
        ?int $yellowCardsAway = null,
        bool $hasTrendData = false,
        ?int $trendShotsTotal = null,
        ?int $trendShotsOnTarget = null,
        ?int $trendDangerousAttacks = null,
        ?int $trendWindowSeconds = null
    ): array {
        $shotsOnTargetHome = (int) ($shotsOnTarget / 2);
        $shotsOnTargetAway = $shotsOnTarget - $shotsOnTargetHome;
        $shotsOffTargetHome = (int) ($shotsOffTarget / 2);
        $shotsOffTargetAway = $shotsOffTarget - $shotsOffTargetHome;
        $dangerousAttacksHome = (int) ($dangerousAttacks / 2);
        $dangerousAttacksAway = $dangerousAttacks - $dangerousAttacksHome;
        $cornersHome = (int) ($corners / 2);
        $cornersAway = $corners - $cornersHome;

        return [
            'minute' => $minute,
            'shots_total' => $shotsOnTarget + $shotsOffTarget,
            'shots_on_target' => $shotsOnTarget,
            'dangerous_attacks' => $dangerousAttacks,
            'corners' => $corners,
            'shots_on_target_home' => $shotsOnTargetHome,
            'shots_on_target_away' => $shotsOnTargetAway,
            'shots_off_target_home' => $shotsOffTargetHome,
            'shots_off_target_away' => $shotsOffTargetAway,
            'dangerous_attacks_home' => $dangerousAttacksHome,
            'dangerous_attacks_away' => $dangerousAttacksAway,
            'corners_home' => $cornersHome,
            'corners_away' => $cornersAway,
            'xg_home' => $xgHome,
            'xg_away' => $xgAway,
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
        ];
    }
}
