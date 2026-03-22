<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests;

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
use Proxbet\Scanner\Algorithms\AlgorithmX\Tests\Fixtures\AlgorithmXScenarioFixtures;

/**
 * Unit tests for AlgorithmX main class.
 * 
 * Tests the orchestration of all components:
 * - Algorithm identification
 * - Data extraction and validation
 * - Probability calculation
 * - Decision making
 * - Error handling
 */
final class AlgorithmXTest extends TestCase
{
    private AlgorithmX $algorithm;

    protected function setUp(): void
    {
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

    // ========== IDENTIFICATION TESTS ==========

    public function testGetId(): void
    {
        // Act
        $id = $this->algorithm->getId();

        // Assert
        $this->assertEquals(4, $id);
        $this->assertEquals(Config::ALGORITHM_ID, $id);
    }

    public function testGetName(): void
    {
        // Act
        $name = $this->algorithm->getName();

        // Assert
        $this->assertEquals('AlgorithmX: Goal Probability', $name);
        $this->assertEquals(Config::ALGORITHM_NAME, $name);
    }

    // ========== ANALYZE METHOD TESTS ==========

    public function testAnalyzeWithValidHighProbabilityMatch(): void
    {
        // Arrange: High activity match
        $matchData = [
            'time' => '20:00',
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 25,
            'live_danger_att_away' => 18,
            'live_shots_on_target_home' => 6,
            'live_shots_on_target_away' => 4,
            'live_shots_off_target_home' => 6,
            'live_shots_off_target_away' => 4,
            'live_corner_home' => 5,
            'live_corner_away' => 3,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('bet', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('debug', $result);
        
        $this->assertIsBool($result['bet']);
        $this->assertIsString($result['reason']);
        $this->assertIsFloat($result['confidence']);
        $this->assertIsArray($result['debug']);
        
        // Confidence should be in valid range
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
    }

    public function testAnalyzeWithValidLowProbabilityMatch(): void
    {
        // Arrange: Low activity match
        $matchData = [
            'time' => '28:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 14,
            'live_danger_att_away' => 6,
            'live_shots_on_target_home' => 3,
            'live_shots_on_target_away' => 1,
            'live_shots_off_target_home' => 4,
            'live_shots_off_target_away' => 2,
            'live_corner_home' => 4,
            'live_corner_away' => 1,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert
        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('Low', $result['reason']);
        $this->assertLessThan(0.20, $result['confidence']);
    }

    public function testAnalyzeWithInvalidDataTooEarly(): void
    {
        // Arrange: Match too early (minute 3)
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

        // Assert
        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('Minute', $result['reason']);
        $this->assertEquals(0.0, $result['confidence']);
        $this->assertTrue($result['debug']['validation_failed']);
    }

    public function testAnalyzeWithInvalidDataTooLate(): void
    {
        // Arrange: Match too late (minute 46)
        $matchData = [
            'time' => '46:00',
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 1,
            'live_danger_att_home' => 30,
            'live_danger_att_away' => 25,
            'live_shots_on_target_home' => 8,
            'live_shots_on_target_away' => 6,
            'live_shots_off_target_home' => 10,
            'live_shots_off_target_away' => 8,
            'live_corner_home' => 6,
            'live_corner_away' => 5,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Should handle gracefully with minimal probability
        $this->assertFalse($result['bet']);
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
    }

    public function testAnalyzeWithMissingData(): void
    {
        // Arrange: Missing critical fields
        $matchData = [
            'time' => '20:00',
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert
        $this->assertFalse($result['bet']);
        $this->assertEquals(0.0, $result['confidence']);
        $this->assertTrue($result['debug']['validation_failed']);
        $this->assertStringContainsString('No live data available', $result['reason']);
    }

    public function testAnalyzeWithFinishedMatch(): void
    {
        // Arrange: Match already finished
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

        // Assert
        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('status', strtolower($result['reason']));
        $this->assertEquals(0.0, $result['confidence']);
    }

    // ========== REALISTIC SCENARIOS ==========

    public function testAnalyzeScenario1LowActivity(): void
    {
        $matchData = AlgorithmXScenarioFixtures::lowActivity();

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Should not bet due to low probability
        $this->assertFalse($result['bet']);
        $this->assertLessThan(0.20, $result['confidence']);
    }

    public function testAnalyzeScenario2HighActivity(): void
    {
        $matchData = AlgorithmXScenarioFixtures::highActivity();

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Probability depends on modifiers (may be lower than expected)
        $this->assertGreaterThan(0.10, $result['confidence']);
        $this->assertIsString($result['reason']);
    }

    public function testAnalyzeScenario3DryPeriod(): void
    {
        $matchData = AlgorithmXScenarioFixtures::dryPeriod();

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Dry period modifier should be applied
        $this->assertTrue($result['debug']['dry_period_applied']);
    }

    public function testAnalyzeWithBlowoutScore(): void
    {
        // Arrange: Large score difference
        $matchData = [
            'time' => '30:00',
            'live_ht_hscore' => 3,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 35,
            'live_danger_att_away' => 10,
            'live_shots_on_target_home' => 10,
            'live_shots_on_target_away' => 2,
            'live_shots_off_target_home' => 12,
            'live_shots_off_target_away' => 3,
            'live_corner_home' => 8,
            'live_corner_away' => 2,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Should not bet (low probability due to defensive play)
        $this->assertFalse($result['bet']);
        $this->assertLessThan(0.20, $result['confidence']);
    }

    // ========== DEBUG DATA TESTS ==========

    public function testAnalyzeReturnsCompleteDebugData(): void
    {
        // Arrange
        $matchData = [
            'time' => '25:00',
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 15,
            'live_danger_att_away' => 10,
            'live_shots_on_target_home' => 4,
            'live_shots_on_target_away' => 2,
            'live_shots_off_target_home' => 5,
            'live_shots_off_target_away' => 3,
            'live_corner_home' => 3,
            'live_corner_away' => 2,
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Debug data should be comprehensive
        $debug = $result['debug'];
        
        $this->assertArrayHasKey('ais_total', $debug);
        $this->assertArrayHasKey('ais_rate', $debug);
        $this->assertArrayHasKey('base_prob', $debug);
        $this->assertArrayHasKey('prob_final', $debug);
        $this->assertArrayHasKey('interpretation', $debug);
        $this->assertArrayHasKey('decision_reason', $debug);
        $this->assertArrayHasKey('algorithm_version', $debug);
        
        $this->assertEquals('AlgorithmX v1.0', $debug['algorithm_version']);
    }

    // ========== EDGE CASES ==========

    public function testAnalyzeWithEmptyMatchData(): void
    {
        // Arrange
        $matchData = [];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Should handle gracefully
        $this->assertFalse($result['bet']);
        $this->assertEquals(0.0, $result['confidence']);
        $this->assertIsString($result['reason']);
    }

    public function testAnalyzeWithNullValues(): void
    {
        // Arrange
        $matchData = [
            'time' => null,
            'live_ht_hscore' => null,
            'live_ht_ascore' => null,
            'live_danger_att_home' => null,
            'live_danger_att_away' => null,
            'match_status' => null,
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Should handle gracefully
        $this->assertFalse($result['bet']);
        $this->assertEquals(0.0, $result['confidence']);
    }

    public function testAnalyzeWithStringNumbers(): void
    {
        // Arrange: Numbers as strings (common from DB)
        $matchData = [
            'time' => '25:00',
            'live_ht_hscore' => '1',
            'live_ht_ascore' => '0',
            'live_danger_att_home' => '15',
            'live_danger_att_away' => '10',
            'live_shots_on_target_home' => '4',
            'live_shots_on_target_away' => '2',
            'live_shots_off_target_home' => '5',
            'live_shots_off_target_away' => '3',
            'live_corner_home' => '3',
            'live_corner_away' => '2',
            'match_status' => 'In Play',
        ];

        // Act
        $result = $this->algorithm->analyze($matchData);

        // Assert: Should handle type coercion
        $this->assertIsFloat($result['confidence']);
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
    }

    // ========== INTEGRATION TESTS ==========

    public function testAnalyzeFullFlowWithBetTrue(): void
    {
        // Arrange: Conditions that should result in bet=true
        $matchData = [
            'time' => '18:00',
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

        // Assert: Very high activity - check if bet is made
        $this->assertGreaterThan(0.50, $result['confidence']);
        $this->assertTrue($result['bet']);
    }

    public function testAnalyzeAcceptsPreExtractedLiveData(): void
    {
        $liveData = [
            'minute' => 18,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 40,
            'dangerous_attacks_away' => 35,
            'shots_home' => 22,
            'shots_away' => 18,
            'shots_on_target_home' => 10,
            'shots_on_target_away' => 8,
            'corners_home' => 7,
            'corners_away' => 6,
            'match_status' => 'In Play',
            'has_data' => true,
        ];

        $result = $this->algorithm->analyze(['live_data' => $liveData]);

        $this->assertTrue($result['bet']);
        $this->assertGreaterThan(0.50, $result['confidence']);
    }

    public function testAnalyzeFullFlowWithBetFalse(): void
    {
        // Arrange: Conditions that should result in bet=false
        $matchData = [
            'time' => '30:00',
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

        // Assert: Low activity should not bet
        $this->assertFalse($result['bet']);
        $this->assertLessThan(0.20, $result['confidence']);
    }

    public function testAnalyzeConsistencyWithSameData(): void
    {
        // Arrange
        $matchData = [
            'time' => '25:00',
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 1,
            'live_danger_att_home' => 18,
            'live_danger_att_away' => 15,
            'live_shots_on_target_home' => 5,
            'live_shots_on_target_away' => 4,
            'live_shots_off_target_home' => 6,
            'live_shots_off_target_away' => 5,
            'live_corner_home' => 4,
            'live_corner_away' => 3,
            'match_status' => 'In Play',
        ];

        // Act: Call twice with same data
        $result1 = $this->algorithm->analyze($matchData);
        $result2 = $this->algorithm->analyze($matchData);

        // Assert: Results should be identical
        $this->assertEquals($result1['bet'], $result2['bet']);
        $this->assertEquals($result1['confidence'], $result2['confidence']);
        $this->assertEquals($result1['reason'], $result2['reason']);
    }
}
