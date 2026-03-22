<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests\Calculators;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ModifierCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Config;

/**
 * Unit tests for ModifierCalculator.
 * 
 * Tests all modifier applications:
 * - Time factor
 * - Score modifier
 * - Dry period modifier
 * - Probability clamping
 */
final class ModifierCalculatorTest extends TestCase
{
    private ModifierCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ModifierCalculator();
    }

    // ========== TIME FACTOR TESTS ==========

    public function testApplyTimeFactorAtStartOfMatch(): void
    {
        // Arrange
        $baseProb = 0.50;
        $minute = 0;

        // Act
        $result = $this->calculator->applyTimeFactor($baseProb, $minute);

        // Assert
        $this->assertEquals(45, $result['time_remaining']);
        $this->assertEquals(1.0, $result['time_factor']);
        // prob = 0.50 * (0.4 + 0.6 * 1.0) = 0.50 * 1.0 = 0.50
        $this->assertEqualsWithDelta(0.50, $result['probability'], 0.01);
    }

    public function testApplyTimeFactorAtHalfTime(): void
    {
        // Arrange
        $baseProb = 0.50;
        $minute = 45;

        // Act
        $result = $this->calculator->applyTimeFactor($baseProb, $minute);

        // Assert
        $this->assertEquals(0, $result['time_remaining']);
        $this->assertEquals(0.0, $result['time_factor']);
        // prob = 0.50 * (0.4 + 0.6 * 0.0) = 0.50 * 0.4 = 0.20
        $this->assertEqualsWithDelta(0.20, $result['probability'], 0.01);
    }

    public function testApplyTimeFactorAtMidpoint(): void
    {
        // Arrange
        $baseProb = 0.60;
        $minute = 22; // 23 minutes remaining

        // Act
        $result = $this->calculator->applyTimeFactor($baseProb, $minute);

        // Assert
        $this->assertEquals(23, $result['time_remaining']);
        $this->assertEqualsWithDelta(0.511, $result['time_factor'], 0.01);
        // prob = 0.60 * (0.4 + 0.6 * 0.511) ≈ 0.60 * 0.707 ≈ 0.424
        $this->assertEqualsWithDelta(0.424, $result['probability'], 0.01);
    }

    public function testApplyTimeFactorLateInHalf(): void
    {
        // Arrange
        $baseProb = 0.70;
        $minute = 40; // 5 minutes remaining

        // Act
        $result = $this->calculator->applyTimeFactor($baseProb, $minute);

        // Assert
        $this->assertEquals(5, $result['time_remaining']);
        $this->assertEqualsWithDelta(0.111, $result['time_factor'], 0.01);
        // prob = 0.70 * (0.4 + 0.6 * 0.111) ≈ 0.70 * 0.467 ≈ 0.327
        $this->assertEqualsWithDelta(0.327, $result['probability'], 0.01);
    }

    // ========== SCORE MODIFIER TESTS ==========

    public function testApplyScoreModifierForDraw(): void
    {
        // Arrange
        $prob = 0.50;
        $scoreHome = 0;
        $scoreAway = 0;

        // Act
        $result = $this->calculator->applyScoreModifier($prob, $scoreHome, $scoreAway);

        // Assert
        $this->assertEquals(0, $result['score_diff']);
        $this->assertEquals(Config::SCORE_MODIFIER_DRAW, $result['modifier']);
        $this->assertEqualsWithDelta(0.525, $result['probability'], 0.01); // 0.50 * 1.05
    }

    public function testApplyScoreModifierForDrawWithGoals(): void
    {
        // Arrange
        $prob = 0.45;
        $scoreHome = 2;
        $scoreAway = 2;

        // Act
        $result = $this->calculator->applyScoreModifier($prob, $scoreHome, $scoreAway);

        // Assert
        $this->assertEquals(0, $result['score_diff']);
        $this->assertEquals(Config::SCORE_MODIFIER_DRAW, $result['modifier']);
        $this->assertEqualsWithDelta(0.4725, $result['probability'], 0.01); // 0.45 * 1.05
    }

    public function testApplyScoreModifierForOneGoalDifference(): void
    {
        // Arrange
        $prob = 0.40;
        $scoreHome = 1;
        $scoreAway = 0;

        // Act
        $result = $this->calculator->applyScoreModifier($prob, $scoreHome, $scoreAway);

        // Assert
        $this->assertEquals(1, $result['score_diff']);
        $this->assertEquals(Config::SCORE_MODIFIER_ONE_GOAL, $result['modifier']);
        $this->assertEqualsWithDelta(0.44, $result['probability'], 0.01); // 0.40 * 1.10
    }

    public function testApplyScoreModifierForOneGoalDifferenceReversed(): void
    {
        // Arrange
        $prob = 0.35;
        $scoreHome = 0;
        $scoreAway = 1;

        // Act
        $result = $this->calculator->applyScoreModifier($prob, $scoreHome, $scoreAway);

        // Assert
        $this->assertEquals(1, $result['score_diff']);
        $this->assertEquals(Config::SCORE_MODIFIER_ONE_GOAL, $result['modifier']);
        $this->assertEqualsWithDelta(0.385, $result['probability'], 0.01); // 0.35 * 1.10
    }

    public function testApplyScoreModifierForTwoGoalDifference(): void
    {
        // Arrange
        $prob = 0.50;
        $scoreHome = 2;
        $scoreAway = 0;

        // Act
        $result = $this->calculator->applyScoreModifier($prob, $scoreHome, $scoreAway);

        // Assert
        $this->assertEquals(2, $result['score_diff']);
        $this->assertEquals(Config::SCORE_MODIFIER_TWO_PLUS, $result['modifier']);
        $this->assertEqualsWithDelta(0.45, $result['probability'], 0.01); // 0.50 * 0.90
    }

    public function testApplyScoreModifierForLargeDifference(): void
    {
        // Arrange
        $prob = 0.60;
        $scoreHome = 4;
        $scoreAway = 0;

        // Act
        $result = $this->calculator->applyScoreModifier($prob, $scoreHome, $scoreAway);

        // Assert
        $this->assertEquals(4, $result['score_diff']);
        $this->assertEquals(Config::SCORE_MODIFIER_TWO_PLUS, $result['modifier']);
        $this->assertEqualsWithDelta(0.54, $result['probability'], 0.01); // 0.60 * 0.90
    }

    // ========== DRY PERIOD MODIFIER TESTS ==========

    public function testApplyDryPeriodModifierWhenApplicable(): void
    {
        // Arrange
        $prob = 0.40;
        $scoreHome = 0;
        $scoreAway = 0;
        $minute = 35; // After 30 minutes

        // Act
        $result = $this->calculator->applyDryPeriodModifier($prob, $scoreHome, $scoreAway, $minute);

        // Assert
        $this->assertTrue($result['applied']);
        $this->assertEqualsWithDelta(0.368, $result['probability'], 0.01); // 0.40 * 0.92
    }

    public function testApplyDryPeriodModifierAtExactThreshold(): void
    {
        // Arrange
        $prob = 0.50;
        $scoreHome = 0;
        $scoreAway = 0;
        $minute = 31; // Just after 30

        // Act
        $result = $this->calculator->applyDryPeriodModifier($prob, $scoreHome, $scoreAway, $minute);

        // Assert
        $this->assertTrue($result['applied']);
        $this->assertEqualsWithDelta(0.46, $result['probability'], 0.01); // 0.50 * 0.92
    }

    public function testApplyDryPeriodModifierNotAppliedBeforeThreshold(): void
    {
        // Arrange
        $prob = 0.40;
        $scoreHome = 0;
        $scoreAway = 0;
        $minute = 30; // At threshold, not after

        // Act
        $result = $this->calculator->applyDryPeriodModifier($prob, $scoreHome, $scoreAway, $minute);

        // Assert
        $this->assertFalse($result['applied']);
        $this->assertEquals(0.40, $result['probability']);
    }

    public function testApplyDryPeriodModifierNotAppliedWithGoals(): void
    {
        // Arrange
        $prob = 0.45;
        $scoreHome = 1;
        $scoreAway = 0;
        $minute = 35;

        // Act
        $result = $this->calculator->applyDryPeriodModifier($prob, $scoreHome, $scoreAway, $minute);

        // Assert
        $this->assertFalse($result['applied']);
        $this->assertEquals(0.45, $result['probability']);
    }

    public function testApplyDryPeriodModifierNotAppliedWithAwayGoal(): void
    {
        // Arrange
        $prob = 0.42;
        $scoreHome = 0;
        $scoreAway = 1;
        $minute = 35;

        // Act
        $result = $this->calculator->applyDryPeriodModifier($prob, $scoreHome, $scoreAway, $minute);

        // Assert
        $this->assertFalse($result['applied']);
        $this->assertEquals(0.42, $result['probability']);
    }

    public function testApplyDryPeriodModifierNotAppliedEarly(): void
    {
        // Arrange
        $prob = 0.38;
        $scoreHome = 0;
        $scoreAway = 0;
        $minute = 20;

        // Act
        $result = $this->calculator->applyDryPeriodModifier($prob, $scoreHome, $scoreAway, $minute);

        // Assert
        $this->assertFalse($result['applied']);
        $this->assertEquals(0.38, $result['probability']);
    }

    // ========== CLAMP PROBABILITY TESTS ==========

    public function testClampProbabilityWithinRange(): void
    {
        // Arrange & Act
        $result1 = $this->calculator->clampProbability(0.50);
        $result2 = $this->calculator->clampProbability(0.25);
        $result3 = $this->calculator->clampProbability(0.75);

        // Assert
        $this->assertEquals(0.50, $result1);
        $this->assertEquals(0.25, $result2);
        $this->assertEquals(0.75, $result3);
    }

    public function testClampProbabilityTooLow(): void
    {
        // Arrange & Act
        $result1 = $this->calculator->clampProbability(0.01);
        $result2 = $this->calculator->clampProbability(0.0);
        $result3 = $this->calculator->clampProbability(-0.1);

        // Assert
        $this->assertEquals(Config::PROBABILITY_MIN, $result1);
        $this->assertEquals(Config::PROBABILITY_MIN, $result2);
        $this->assertEquals(Config::PROBABILITY_MIN, $result3);
    }

    public function testClampProbabilityTooHigh(): void
    {
        // Arrange & Act
        $result1 = $this->calculator->clampProbability(0.99);
        $result2 = $this->calculator->clampProbability(1.0);
        $result3 = $this->calculator->clampProbability(1.5);

        // Assert
        $this->assertEquals(Config::PROBABILITY_MAX, $result1);
        $this->assertEquals(Config::PROBABILITY_MAX, $result2);
        $this->assertEquals(Config::PROBABILITY_MAX, $result3);
    }

    public function testClampProbabilityAtBoundaries(): void
    {
        // Arrange & Act
        $resultMin = $this->calculator->clampProbability(Config::PROBABILITY_MIN);
        $resultMax = $this->calculator->clampProbability(Config::PROBABILITY_MAX);

        // Assert
        $this->assertEquals(Config::PROBABILITY_MIN, $resultMin);
        $this->assertEquals(Config::PROBABILITY_MAX, $resultMax);
    }

    // ========== INTEGRATION TESTS (Multiple Modifiers) ==========

    public function testCombinedModifiersTypicalScenario(): void
    {
        // Arrange: Active match at 25 minutes, 0-0
        $baseProb = 0.50;
        $minute = 25;
        $scoreHome = 0;
        $scoreAway = 0;

        // Act: Apply all modifiers in sequence
        $timeResult = $this->calculator->applyTimeFactor($baseProb, $minute);
        $scoreResult = $this->calculator->applyScoreModifier($timeResult['probability'], $scoreHome, $scoreAway);
        $dryResult = $this->calculator->applyDryPeriodModifier($scoreResult['probability'], $scoreHome, $scoreAway, $minute);
        $final = $this->calculator->clampProbability($dryResult['probability']);

        // Assert: Verify final probability is reasonable
        $this->assertGreaterThan(0.0, $final);
        $this->assertLessThan(1.0, $final);
        $this->assertFalse($dryResult['applied']); // Not dry period yet (< 30 min)
    }

    public function testCombinedModifiersDryPeriodScenario(): void
    {
        // Arrange: Passive match at 35 minutes, still 0-0
        $baseProb = 0.35;
        $minute = 35;
        $scoreHome = 0;
        $scoreAway = 0;

        // Act: Apply all modifiers
        $timeResult = $this->calculator->applyTimeFactor($baseProb, $minute);
        $scoreResult = $this->calculator->applyScoreModifier($timeResult['probability'], $scoreHome, $scoreAway);
        $dryResult = $this->calculator->applyDryPeriodModifier($scoreResult['probability'], $scoreHome, $scoreAway, $minute);
        $final = $this->calculator->clampProbability($dryResult['probability']);

        // Assert
        $this->assertTrue($dryResult['applied']); // Dry period should apply
        $this->assertLessThan($scoreResult['probability'], $dryResult['probability']); // Should be reduced
    }

    public function testCombinedModifiersOneGoalLead(): void
    {
        // Arrange: 1-0 at 28 minutes
        $baseProb = 0.45;
        $minute = 28;
        $scoreHome = 1;
        $scoreAway = 0;

        // Act
        $timeResult = $this->calculator->applyTimeFactor($baseProb, $minute);
        $scoreResult = $this->calculator->applyScoreModifier($timeResult['probability'], $scoreHome, $scoreAway);
        $dryResult = $this->calculator->applyDryPeriodModifier($scoreResult['probability'], $scoreHome, $scoreAway, $minute);
        $final = $this->calculator->clampProbability($dryResult['probability']);

        // Assert
        $this->assertFalse($dryResult['applied']); // No dry period (goals scored)
        $this->assertEquals(Config::SCORE_MODIFIER_ONE_GOAL, $scoreResult['modifier']); // 1.10 modifier
        $this->assertGreaterThan($timeResult['probability'], $scoreResult['probability']); // Should be increased
    }
}
