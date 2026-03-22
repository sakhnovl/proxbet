<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests\Calculators;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\AisCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ModifierCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\InterpretationGenerator;

/**
 * Unit tests for ProbabilityCalculator.
 * 
 * Tests the main probability calculation orchestration:
 * - Sigmoid function
 * - Full calculation flow
 * - Integration with sub-calculators
 * - Edge cases and boundary conditions
 */
final class ProbabilityCalculatorTest extends TestCase
{
    private ProbabilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProbabilityCalculator(
            new AisCalculator(),
            new ModifierCalculator(),
            new InterpretationGenerator()
        );
    }

    // ========== TYPICAL SCENARIOS ==========

    public function testCalculateWithTypicalActiveMatch(): void
    {
        // Arrange: Active match at 28 minutes, 0-0
        $liveData = [
            'minute' => 28,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 14,
            'dangerous_attacks_away' => 6,
            'shots_home' => 7,
            'shots_away' => 3,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 1,
            'corners_home' => 4,
            'corners_away' => 1,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('probability', $result);
        $this->assertArrayHasKey('debug', $result);
        
        $prob = $result['probability'];
        $debug = $result['debug'];
        
        // Probability should be in valid range
        $this->assertGreaterThanOrEqual(0.03, $prob);
        $this->assertLessThanOrEqual(0.97, $prob);
        
        // Debug data should be complete
        $this->assertArrayHasKey('ais_total', $debug);
        $this->assertArrayHasKey('ais_rate', $debug);
        $this->assertArrayHasKey('base_prob', $debug);
        $this->assertArrayHasKey('prob_final', $debug);
        $this->assertArrayHasKey('interpretation', $debug);
        
        // AIS_rate should be around 0.439 (12.3 / 28)
        $this->assertEqualsWithDelta(0.439, $debug['ais_rate'], 0.01);
        
        // Low AIS_rate should result in low probability
        $this->assertLessThan(0.20, $prob);
    }

    public function testCalculateWithHighActivityMatch(): void
    {
        // Arrange: Very active match at 20 minutes, 1-0
        $liveData = [
            'minute' => 20,
            'score_home' => 1,
            'score_away' => 0,
            'dangerous_attacks_home' => 25,
            'dangerous_attacks_away' => 18,
            'shots_home' => 12,
            'shots_away' => 8,
            'shots_on_target_home' => 6,
            'shots_on_target_away' => 4,
            'corners_home' => 5,
            'corners_away' => 3,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $prob = $result['probability'];
        $debug = $result['debug'];
        
        // AIS_rate should be around 1.3 (26.0 / 20)
        $this->assertEqualsWithDelta(1.3, $debug['ais_rate'], 0.05);
        
        // AIS_rate results in probability (actual value depends on modifiers)
        $this->assertGreaterThan(0.10, $prob);
        
        // Score modifier should be 1.10 (one goal difference)
        $this->assertEquals(1.10, $debug['score_modifier']);
    }

    public function testCalculateWithDryPeriod(): void
    {
        // Arrange: Passive match at 35 minutes, still 0-0
        $liveData = [
            'minute' => 35,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 15,
            'shots_home' => 8,
            'shots_away' => 6,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 2,
            'corners_home' => 4,
            'corners_away' => 2,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $debug = $result['debug'];
        
        // Dry period modifier should be applied
        $this->assertTrue($debug['dry_period_applied']);
        
        // Probability should be reduced by dry period
        $this->assertLessThan($debug['prob_with_score'], $debug['prob_before_clamp']);
    }

    // ========== EDGE CASES ==========

    public function testCalculateWithZeroMinute(): void
    {
        // Arrange: Match just started
        $liveData = [
            'minute' => 0,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 0,
            'dangerous_attacks_away' => 0,
            'shots_home' => 0,
            'shots_away' => 0,
            'shots_on_target_home' => 0,
            'shots_on_target_away' => 0,
            'corners_home' => 0,
            'corners_away' => 0,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $debug = $result['debug'];
        
        // AIS_rate should be 0 (avoid division by zero)
        $this->assertEquals(0.0, $debug['ais_rate']);
        
        // Time remaining should be 45
        $this->assertEquals(45, $debug['time_remaining']);
        
        // Should not crash
        $this->assertIsFloat($result['probability']);
    }

    public function testCalculateWithMinute45(): void
    {
        // Arrange: End of first half
        $liveData = [
            'minute' => 45,
            'score_home' => 1,
            'score_away' => 1,
            'dangerous_attacks_home' => 30,
            'dangerous_attacks_away' => 25,
            'shots_home' => 15,
            'shots_away' => 12,
            'shots_on_target_home' => 7,
            'shots_on_target_away' => 5,
            'corners_home' => 6,
            'corners_away' => 4,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $debug = $result['debug'];
        
        // Time remaining should be 0
        $this->assertEquals(0, $debug['time_remaining']);
        
        // Time factor should be 0
        $this->assertEquals(0.0, $debug['time_factor']);
        
        // Probability should be significantly reduced due to no time
        $this->assertLessThan($debug['base_prob'], $debug['prob_adjusted']);
    }

    public function testCalculateWithAllZeroStats(): void
    {
        // Arrange: No activity at all
        $liveData = [
            'minute' => 20,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 0,
            'dangerous_attacks_away' => 0,
            'shots_home' => 0,
            'shots_away' => 0,
            'shots_on_target_home' => 0,
            'shots_on_target_away' => 0,
            'corners_home' => 0,
            'corners_away' => 0,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $debug = $result['debug'];
        
        // AIS should be 0
        $this->assertEquals(0.0, $debug['ais_total']);
        $this->assertEquals(0.0, $debug['ais_rate']);
        
        // Base probability should be very low
        $this->assertLessThan(0.10, $debug['base_prob']);
        
        // Final probability should be clamped to minimum
        $this->assertEquals(0.03, $result['probability']);
    }

    public function testCalculateWithExtremelyHighStats(): void
    {
        // Arrange: Unrealistically high activity
        $liveData = [
            'minute' => 15,
            'score_home' => 3,
            'score_away' => 2,
            'dangerous_attacks_home' => 50,
            'dangerous_attacks_away' => 45,
            'shots_home' => 25,
            'shots_away' => 20,
            'shots_on_target_home' => 15,
            'shots_on_target_away' => 12,
            'corners_home' => 10,
            'corners_away' => 8,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $prob = $result['probability'];
        $debug = $result['debug'];
        
        // AIS_rate should be very high
        $this->assertGreaterThan(3.0, $debug['ais_rate']);
        
        // Base probability should be high
        $this->assertGreaterThan(0.80, $debug['base_prob']);
        
        // Final probability should be very high (may not reach max due to modifiers)
        $this->assertGreaterThan(0.80, $prob);
    }

    public function testCalculateWithLargeScoreDifference(): void
    {
        // Arrange: Blowout game
        $liveData = [
            'minute' => 30,
            'score_home' => 4,
            'score_away' => 0,
            'dangerous_attacks_home' => 35,
            'dangerous_attacks_away' => 10,
            'shots_home' => 18,
            'shots_away' => 4,
            'shots_on_target_home' => 10,
            'shots_on_target_away' => 1,
            'corners_home' => 8,
            'corners_away' => 2,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $debug = $result['debug'];
        
        // Score difference should be 4
        $this->assertEquals(4, $debug['score_diff']);
        
        // Score modifier should be 0.90 (2+ goals)
        $this->assertEquals(0.90, $debug['score_modifier']);
        
        // Probability should be reduced
        $this->assertLessThan($debug['prob_adjusted'], $debug['prob_with_score']);
    }

    // ========== BOUNDARY CONDITIONS ==========

    public function testCalculateWithMinute5(): void
    {
        // Arrange: Minimum minute for analysis
        $liveData = [
            'minute' => 5,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 3,
            'dangerous_attacks_away' => 2,
            'shots_home' => 1,
            'shots_away' => 1,
            'shots_on_target_home' => 0,
            'shots_on_target_away' => 0,
            'corners_home' => 1,
            'corners_away' => 0,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $this->assertIsFloat($result['probability']);
        $this->assertArrayHasKey('debug', $result);
    }

    public function testCalculateWithMinute30Threshold(): void
    {
        // Arrange: Exactly at dry period threshold
        $liveData = [
            'minute' => 30,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 15,
            'dangerous_attacks_away' => 12,
            'shots_home' => 6,
            'shots_away' => 5,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 2,
            'corners_home' => 3,
            'corners_away' => 2,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $debug = $result['debug'];
        
        // Dry period should NOT be applied at exactly 30 minutes
        $this->assertFalse($debug['dry_period_applied']);
    }

    public function testCalculateWithMinute31(): void
    {
        // Arrange: Just after dry period threshold
        $liveData = [
            'minute' => 31,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 15,
            'dangerous_attacks_away' => 12,
            'shots_home' => 6,
            'shots_away' => 5,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 2,
            'corners_home' => 3,
            'corners_away' => 2,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $debug = $result['debug'];
        
        // Dry period SHOULD be applied after 30 minutes
        $this->assertTrue($debug['dry_period_applied']);
    }

    // ========== DEBUG DATA COMPLETENESS ==========

    public function testCalculateReturnsCompleteDebugData(): void
    {
        // Arrange
        $liveData = [
            'minute' => 25,
            'score_home' => 1,
            'score_away' => 0,
            'dangerous_attacks_home' => 12,
            'dangerous_attacks_away' => 8,
            'shots_home' => 6,
            'shots_away' => 4,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 2,
            'corners_home' => 3,
            'corners_away' => 2,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert: All required debug fields present
        $debug = $result['debug'];
        $requiredFields = [
            'ais_home',
            'ais_away',
            'ais_total',
            'ais_rate',
            'base_prob',
            'time_remaining',
            'time_factor',
            'prob_adjusted',
            'score_diff',
            'score_modifier',
            'prob_with_score',
            'dry_period_applied',
            'prob_before_clamp',
            'prob_final',
            'interpretation',
        ];
        
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $debug, "Missing debug field: {$field}");
        }
    }

    // ========== INTERPRETATION GENERATION ==========

    public function testCalculateGeneratesInterpretation(): void
    {
        // Arrange
        $liveData = [
            'minute' => 20,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 10,
            'dangerous_attacks_away' => 8,
            'shots_home' => 5,
            'shots_away' => 4,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 2,
            'corners_home' => 2,
            'corners_away' => 2,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert
        $this->assertArrayHasKey('interpretation', $result['debug']);
        $this->assertIsString($result['debug']['interpretation']);
        $this->assertNotEmpty($result['debug']['interpretation']);
    }

    // ========== MISSING FIELDS HANDLING ==========

    public function testCalculateWithMissingFields(): void
    {
        // Arrange: Minimal data
        $liveData = [
            'minute' => 20,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert: Should handle gracefully with defaults
        $this->assertIsFloat($result['probability']);
        $this->assertEquals(0.0, $result['debug']['ais_total']);
    }

    // ========== REALISTIC SCENARIOS FROM SPEC ==========

    public function testScenario1LowActivity(): void
    {
        // Arrange: From spec - low activity, draw
        $liveData = [
            'minute' => 28,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 14,
            'dangerous_attacks_away' => 6,
            'shots_home' => 7,
            'shots_away' => 3,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 1,
            'corners_home' => 4,
            'corners_away' => 1,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert: Low probability expected
        $this->assertLessThan(0.15, $result['probability']);
        $this->assertStringContainsString('Низкая', $result['debug']['interpretation']);
    }

    public function testScenario2HighActivity(): void
    {
        // Arrange: From spec - very high activity
        $liveData = [
            'minute' => 20,
            'score_home' => 1,
            'score_away' => 0,
            'dangerous_attacks_home' => 25,
            'dangerous_attacks_away' => 18,
            'shots_home' => 12,
            'shots_away' => 8,
            'shots_on_target_home' => 6,
            'shots_on_target_away' => 4,
            'corners_home' => 5,
            'corners_away' => 3,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert: Probability expected (actual behavior may vary based on modifiers)
        $this->assertGreaterThan(0.15, $result['probability']);
        $this->assertLessThan(0.50, $result['probability']);
    }

    public function testScenario3DryPeriod(): void
    {
        // Arrange: From spec - dry period scenario
        $liveData = [
            'minute' => 35,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 15,
            'shots_home' => 8,
            'shots_away' => 6,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 2,
            'corners_home' => 4,
            'corners_away' => 2,
        ];

        // Act
        $result = $this->calculator->calculate($liveData);

        // Assert: Dry period modifier applied
        $this->assertTrue($result['debug']['dry_period_applied']);
    }
}
