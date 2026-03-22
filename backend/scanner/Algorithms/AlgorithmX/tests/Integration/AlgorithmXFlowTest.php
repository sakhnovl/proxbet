<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmX\AlgorithmX;
use Proxbet\Scanner\Algorithms\AlgorithmX\Config;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataExtractor;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataValidator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\AisCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ModifierCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\InterpretationGenerator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Filters\DecisionFilter;

/**
 * Integration tests for AlgorithmX full flow.
 * 
 * Tests the complete end-to-end flow from match data input
 * to betting decision output, verifying all components work together correctly.
 */
final class AlgorithmXFlowTest extends TestCase
{
    private AlgorithmX $algorithm;

    protected function setUp(): void
    {
        // Build the complete algorithm with all real dependencies
        $config = new Config();
        $extractor = new DataExtractor();
        $validator = new DataValidator();
        
        $probabilityCalculator = new ProbabilityCalculator(
            new AisCalculator(),
            new ModifierCalculator(),
            new InterpretationGenerator()
        );
        
        $filter = new DecisionFilter();
        
        $this->algorithm = new AlgorithmX(
            $config,
            $extractor,
            $validator,
            $probabilityCalculator,
            $filter
        );
    }

    // ========== COMPLETE FLOW TESTS ==========

    public function testCompleteFlowHighActivityShouldBet(): void
    {
        // Arrange: Very active match with good conditions
        $matchData = [
            'time' => '14:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 40,
            'live_danger_att_away' => 35,
            'live_shots_on_target_home' => 10,
            'live_shots_on_target_away' => 8,
            'live_shots_off_target_home' => 12,
            'live_shots_off_target_away' => 10,
            'live_corner_home' => 7,
            'live_corner_away' => 6,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Complete result structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('bet', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('debug', $result);
        
        // Should bet due to high activity
        $this->assertTrue($result['bet'], 'Expected bet=true for high activity match');
        $this->assertGreaterThan(0.50, $result['confidence'], 'Expected high confidence');
        
        // Debug data should show high AIS
        $debug = $result['debug'];
        $this->assertGreaterThan(2.0, $debug['ais_rate'], 'Expected high AIS rate');
        $this->assertGreaterThan(0.50, $debug['base_prob'], 'Expected high base probability');
        
        // Interpretation should reflect high activity
        $this->assertStringContainsString('активность', $debug['interpretation']);
    }

    public function testCompleteFlowLowActivityShouldNotBet(): void
    {
        // Arrange: Passive match with low activity
        $matchData = [
            'time' => '18:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 5,
            'live_danger_att_away' => 4,
            'live_shots_on_target_home' => 1,
            'live_shots_on_target_away' => 1,
            'live_shots_off_target_home' => 2,
            'live_shots_off_target_away' => 1,
            'live_corner_home' => 1,
            'live_corner_away' => 1,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert
        $this->assertFalse($result['bet'], 'Expected bet=false for low activity match');
        $this->assertLessThan(0.20, $result['confidence'], 'Expected low confidence');
        
        // Debug data should show low AIS
        $debug = $result['debug'];
        $this->assertLessThan(1.0, $debug['ais_rate'], 'Expected low AIS rate');
        $this->assertLessThan(0.20, $debug['base_prob'], 'Expected low base probability');
        
        // Interpretation should reflect low activity
        $this->assertStringContainsString('Низкая', $debug['interpretation']);
    }

    public function testCompleteFlowDryPeriodModifierApplied(): void
    {
        // Arrange: 0-0 after 35 minutes (dry period)
        $matchData = [
            'time' => '35:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 20,
            'live_danger_att_away' => 15,
            'live_shots_on_target_home' => 3,
            'live_shots_on_target_away' => 2,
            'live_shots_off_target_home' => 5,
            'live_shots_off_target_away' => 4,
            'live_corner_home' => 4,
            'live_corner_away' => 2,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Dry period modifier should be applied
        $debug = $result['debug'];
        $this->assertTrue($debug['dry_period_applied'], 'Expected dry period modifier to be applied');
        
        // Probability should be reduced by dry period
        $this->assertLessThan($debug['prob_with_score'], $debug['prob_before_clamp']);
        
        // Should not bet due to dry period
        $this->assertFalse($result['bet']);
    }

    public function testCompleteFlowScoreModifierOneGoal(): void
    {
        // Arrange: 1-0 match with good activity
        $matchData = [
            'time' => '25:00',
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 18,
            'live_danger_att_away' => 15,
            'live_shots_on_target_home' => 5,
            'live_shots_on_target_away' => 4,
            'live_shots_off_target_home' => 7,
            'live_shots_off_target_away' => 6,
            'live_corner_home' => 4,
            'live_corner_away' => 3,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Score modifier should be 1.10 (one goal difference)
        $debug = $result['debug'];
        $this->assertEquals(1, $debug['score_diff']);
        $this->assertEquals(1.10, $debug['score_modifier']);
        
        // Probability should be boosted
        $this->assertGreaterThan($debug['prob_adjusted'], $debug['prob_with_score']);
    }

    public function testCompleteFlowScoreModifierBlowout(): void
    {
        // Arrange: 3-0 blowout
        $matchData = [
            'time' => '30:00',
            'live_ht_hscore' => 3,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 52,
            'live_danger_att_away' => 34,
            'live_shots_on_target_home' => 13,
            'live_shots_on_target_away' => 8,
            'live_shots_off_target_home' => 15,
            'live_shots_off_target_away' => 10,
            'live_corner_home' => 9,
            'live_corner_away' => 6,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Should not bet due to large score difference
        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('score difference', $result['reason']);
        
        $debug = $result['debug'];
        $this->assertEquals(3, $debug['score_diff']);
        $this->assertEquals(0.90, $debug['score_modifier']);
    }

    public function testCompleteFlowTimeFactorEarlyMatch(): void
    {
        // Arrange: Early in match (minute 10)
        $matchData = [
            'time' => '10:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 12,
            'live_danger_att_away' => 10,
            'live_shots_on_target_home' => 3,
            'live_shots_on_target_away' => 2,
            'live_shots_off_target_home' => 4,
            'live_shots_off_target_away' => 3,
            'live_corner_home' => 2,
            'live_corner_away' => 2,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Time factor should be high (35 minutes remaining)
        $debug = $result['debug'];
        $this->assertEquals(35, $debug['time_remaining']);
        $this->assertGreaterThan(0.75, $debug['time_factor']);
        
        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('Medium-low probability', $result['reason']);
    }

    public function testCompleteFlowTimeFactorLateMatch(): void
    {
        // Arrange: Late in match (minute 42)
        $matchData = [
            'time' => '42:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 70,
            'live_danger_att_away' => 62,
            'live_shots_on_target_home' => 14,
            'live_shots_on_target_away' => 12,
            'live_shots_off_target_home' => 18,
            'live_shots_off_target_away' => 16,
            'live_corner_home' => 9,
            'live_corner_away' => 8,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Time factor should be low (3 minutes remaining)
        $debug = $result['debug'];
        $this->assertEquals(3, $debug['time_remaining']);
        $this->assertLessThan(0.10, $debug['time_factor']);
        
        // Should not bet due to little time remaining
        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('too little time remaining', $result['reason']);
    }

    // ========== VALIDATION FLOW TESTS ==========

    public function testCompleteFlowValidationFailsTooEarly(): void
    {
        // Arrange: Minute 3 (below MIN_MINUTE)
        $matchData = [
            'time' => '03:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 2,
            'live_danger_att_away' => 1,
            'live_shots_on_target_home' => 0,
            'live_shots_on_target_away' => 0,
            'live_shots_off_target_home' => 1,
            'live_shots_off_target_away' => 0,
            'live_corner_home' => 0,
            'live_corner_away' => 0,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Validation should fail
        $this->assertFalse($result['bet']);
        $this->assertEquals(0.0, $result['confidence']);
        $this->assertTrue($result['debug']['validation_failed']);
        $this->assertStringContainsString('Minute', $result['reason']);
    }

    public function testCompleteFlowValidationFailsFinishedMatch(): void
    {
        // Arrange: Finished match
        $matchData = [
            'time' => '90:00',
            'live_ht_hscore' => 2,
            'live_ht_ascore' => 1,
            'live_danger_att_home' => 40,
            'live_danger_att_away' => 30,
            'live_shots_on_target_home' => 12,
            'live_shots_on_target_away' => 8,
            'live_shots_off_target_home' => 15,
            'live_shots_off_target_away' => 10,
            'live_corner_home' => 8,
            'live_corner_away' => 6,
            'match_status' => 'Finished',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Validation should fail
        $this->assertFalse($result['bet']);
        $this->assertEquals(0.0, $result['confidence']);
        $this->assertStringContainsString('status', strtolower($result['reason']));
    }

    // ========== REALISTIC MATCH SCENARIOS ==========

    public function testRealisticScenarioBalancedMatch(): void
    {
        // Arrange: Balanced, medium activity match
        $matchData = [
            'time' => '22:00',
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 1,
            'live_danger_att_home' => 26,
            'live_danger_att_away' => 24,
            'live_shots_on_target_home' => 7,
            'live_shots_on_target_away' => 6,
            'live_shots_off_target_home' => 9,
            'live_shots_off_target_away' => 8,
            'live_corner_home' => 5,
            'live_corner_away' => 4,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Medium probability, decision depends on additional factors
        $this->assertGreaterThan(0.20, $result['confidence']);
        $this->assertLessThan(0.60, $result['confidence']);
        
        $debug = $result['debug'];
        $this->assertEquals(0, $debug['score_diff']); // Draw
        $this->assertEquals(1.05, $debug['score_modifier']); // Draw modifier
    }

    public function testRealisticScenarioOneSidedDomination(): void
    {
        // Arrange: One team dominating but still 0-0
        $matchData = [
            'time' => '20:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 40,
            'live_danger_att_away' => 12,
            'live_shots_on_target_home' => 11,
            'live_shots_on_target_away' => 3,
            'live_shots_off_target_home' => 14,
            'live_shots_off_target_away' => 4,
            'live_corner_home' => 7,
            'live_corner_away' => 1,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: High AIS but dry period applied
        $debug = $result['debug'];
        $this->assertGreaterThan(1.5, $debug['ais_rate']);
        $this->assertFalse($debug['dry_period_applied']);
        
        // Decision depends on whether probability is still high enough after dry period
        $this->assertIsFloat($result['confidence']);
    }

    public function testRealisticScenarioHighScoringMatch(): void
    {
        // Arrange: High-scoring, open match
        $matchData = [
            'time' => '20:00',
            'live_ht_hscore' => 2,
            'live_ht_ascore' => 2,
            'live_danger_att_home' => 32,
            'live_danger_att_away' => 30,
            'live_shots_on_target_home' => 9,
            'live_shots_on_target_away' => 8,
            'live_shots_off_target_home' => 11,
            'live_shots_off_target_away' => 10,
            'live_corner_home' => 6,
            'live_corner_away' => 5,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Draw modifier applied, no dry period
        $debug = $result['debug'];
        $this->assertEquals(0, $debug['score_diff']);
        $this->assertEquals(1.05, $debug['score_modifier']);
        $this->assertFalse($debug['dry_period_applied']);
        
        // Should have reasonable probability
        $this->assertGreaterThan(0.15, $result['confidence']);
    }

    // ========== DATA CONSISTENCY TESTS ==========

    public function testFlowConsistencyMultipleCalls(): void
    {
        // Arrange
        $matchData = [
            'time' => '25:00',
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 15,
            'live_danger_att_away' => 12,
            'live_shots_on_target_home' => 4,
            'live_shots_on_target_away' => 3,
            'live_shots_off_target_home' => 5,
            'live_shots_off_target_away' => 4,
            'live_corner_home' => 3,
            'live_corner_away' => 2,
            'match_status' => 'In Play',
        ];

        // Act: Call multiple times
        $result1 = $this->algorithm->analyze($matchData);
        $result2 = $this->algorithm->analyze($matchData);
        $result3 = $this->algorithm->analyze($matchData);

        // Assert: All results should be identical
        $this->assertEquals($result1['bet'], $result2['bet']);
        $this->assertEquals($result1['bet'], $result3['bet']);
        $this->assertEquals($result1['confidence'], $result2['confidence']);
        $this->assertEquals($result1['confidence'], $result3['confidence']);
        $this->assertEquals($result1['reason'], $result2['reason']);
        $this->assertEquals($result1['reason'], $result3['reason']);
    }

    public function testFlowWithAllComponentsIntegrated(): void
    {
        // Arrange: Complex scenario testing all components
        $matchData = [
            'time' => '33:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 22,
            'live_danger_att_away' => 18,
            'live_shots_on_target_home' => 6,
            'live_shots_on_target_away' => 5,
            'live_shots_off_target_home' => 8,
            'live_shots_off_target_away' => 6,
            'live_corner_home' => 5,
            'live_corner_away' => 4,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Verify all components contributed
        $debug = $result['debug'];
        
        // 1. AIS Calculator worked
        $this->assertGreaterThan(0, $debug['ais_total']);
        $this->assertGreaterThan(0, $debug['ais_rate']);
        
        // 2. Probability Calculator worked
        $this->assertGreaterThan(0, $debug['base_prob']);
        $this->assertGreaterThan(0, $debug['prob_final']);
        
        // 3. Modifier Calculator worked
        $this->assertArrayHasKey('time_factor', $debug);
        $this->assertArrayHasKey('score_modifier', $debug);
        $this->assertArrayHasKey('dry_period_applied', $debug);
        
        // 4. Interpretation Generator worked
        $this->assertNotEmpty($debug['interpretation']);
        
        // 5. Decision Filter worked
        $this->assertArrayHasKey('decision_reason', $debug);
        $this->assertIsBool($result['bet']);
        
        // 6. Data Extractor and Validator worked (no validation_failed)
        $this->assertArrayNotHasKey('validation_failed', $debug);
    }
}
