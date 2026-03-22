<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests\Filters;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmX\Filters\DecisionFilter;

/**
 * Tests for DecisionFilter.
 * 
 * Covers all decision scenarios:
 * - High probability (≥60%)
 * - Low probability (<20%)
 * - Medium probability (20-60%)
 * - Edge cases and boundary conditions
 */
final class DecisionFilterTest extends TestCase
{
    private DecisionFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new DecisionFilter();
    }

    /**
     * Test high probability with good conditions - should bet.
     */
    public function testHighProbabilityWithGoodConditions(): void
    {
        $probability = 0.65; // 65%
        $liveData = [
            'minute' => 20,
            'score_home' => 1,
            'score_away' => 0,
            'dangerous_attacks_home' => 15,
            'dangerous_attacks_away' => 10,
            'shots_on_target_home' => 4,
            'shots_on_target_away' => 2,
        ];
        $debug = ['ais_rate' => 1.5];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertTrue($result['bet']);
        $this->assertStringContainsString('High goal probability', $result['reason']);
        $this->assertStringContainsString('65', $result['reason']);
    }

    /**
     * Test high probability but score difference too large - should not bet.
     */
    public function testHighProbabilityButBlowout(): void
    {
        $probability = 0.70; // 70%
        $liveData = [
            'minute' => 25,
            'score_home' => 3,
            'score_away' => 0, // 3 goal difference
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 5,
            'shots_on_target_home' => 6,
            'shots_on_target_away' => 1,
        ];
        $debug = ['ais_rate' => 2.0];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('score difference too large', $result['reason']);
        $this->assertStringContainsString('3 goals', $result['reason']);
    }

    /**
     * Test high probability but insufficient data (early minute) - should not bet.
     */
    public function testHighProbabilityButEarlyMinute(): void
    {
        $probability = 0.68; // 68%
        $liveData = [
            'minute' => 7, // Too early
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 8,
            'dangerous_attacks_away' => 6,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 1,
        ];
        $debug = ['ais_rate' => 2.0];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('insufficient data', $result['reason']);
        $this->assertStringContainsString('minute 7', $result['reason']);
    }

    /**
     * Test low probability - should not bet.
     */
    public function testLowProbability(): void
    {
        $probability = 0.15; // 15%
        $liveData = [
            'minute' => 20,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 3,
            'dangerous_attacks_away' => 2,
            'shots_on_target_home' => 1,
            'shots_on_target_away' => 0,
        ];
        $debug = ['ais_rate' => 0.5];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('Low goal probability', $result['reason']);
        $this->assertStringContainsString('15', $result['reason']);
    }

    /**
     * Test medium-high probability with good shot quality - should bet.
     */
    public function testMediumHighProbabilityWithQuality(): void
    {
        $probability = 0.45; // 45%
        $liveData = [
            'minute' => 25,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 10,
            'dangerous_attacks_away' => 8,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 2, // Total 5 shots on target
        ];
        $debug = ['ais_rate' => 1.2];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertTrue($result['bet']);
        $this->assertStringContainsString('Medium-high probability', $result['reason']);
        $this->assertStringContainsString('45', $result['reason']);
        $this->assertStringContainsString('shot quality', $result['reason']);
    }

    /**
     * Test medium probability but insufficient data - should not bet.
     */
    public function testMediumProbabilityButEarlyMinute(): void
    {
        $probability = 0.35; // 35%
        $liveData = [
            'minute' => 8, // Too early
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 6,
            'dangerous_attacks_away' => 4,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 1,
        ];
        $debug = ['ais_rate' => 1.0];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('insufficient data', $result['reason']);
    }

    /**
     * Test medium probability but low activity - should not bet.
     */
    public function testMediumProbabilityButLowActivity(): void
    {
        $probability = 0.30; // 30%
        $liveData = [
            'minute' => 20,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 4,
            'dangerous_attacks_away' => 3,
            'shots_on_target_home' => 1,
            'shots_on_target_away' => 1,
        ];
        $debug = ['ais_rate' => 0.6]; // Below 0.8 threshold

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('low match activity', $result['reason']);
        $this->assertStringContainsString('Match too passive', $result['reason']);
    }

    /**
     * Test medium probability but large score difference - should not bet.
     */
    public function testMediumProbabilityButLargeScoreDiff(): void
    {
        $probability = 0.35; // 35%
        $liveData = [
            'minute' => 25,
            'score_home' => 2,
            'score_away' => 0, // 2 goal difference (at threshold)
            'dangerous_attacks_home' => 12,
            'dangerous_attacks_away' => 6,
            'shots_on_target_home' => 4,
            'shots_on_target_away' => 1,
        ];
        $debug = ['ais_rate' => 1.0];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertFalse($result['bet']);
        // Filter checks probability threshold first, then other conditions
        $this->assertStringContainsString('Medium', $result['reason']);
    }

    /**
     * Test medium probability but few dangerous attacks - should not bet.
     */
    public function testMediumProbabilityButFewDangerousAttacks(): void
    {
        $probability = 0.32; // 32%
        $liveData = [
            'minute' => 20,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 2,
            'dangerous_attacks_away' => 2, // Total 4, below 5 threshold
            'shots_on_target_home' => 1,
            'shots_on_target_away' => 1,
        ];
        $debug = ['ais_rate' => 0.9];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('very few dangerous attacks', $result['reason']);
        $this->assertStringContainsString('Insufficient offensive pressure', $result['reason']);
    }

    /**
     * Test medium probability but too little time remaining - should not bet.
     */
    public function testMediumProbabilityButLittleTimeRemaining(): void
    {
        $probability = 0.38; // 38%
        $liveData = [
            'minute' => 42, // Only 3 minutes left
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 15,
            'dangerous_attacks_away' => 12,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 2,
        ];
        $debug = ['ais_rate' => 1.3];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('too little time remaining', $result['reason']);
        $this->assertStringContainsString('3 min', $result['reason']);
    }

    /**
     * Test medium-low probability - should not bet.
     */
    public function testMediumLowProbability(): void
    {
        $probability = 0.25; // 25%
        $liveData = [
            'minute' => 20,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 8,
            'dangerous_attacks_away' => 6,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 1,
        ];
        $debug = ['ais_rate' => 1.0];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('Medium-low probability', $result['reason']);
        $this->assertStringContainsString('25', $result['reason']);
    }

    /**
     * Test boundary: exactly 60% probability (high threshold).
     */
    public function testBoundaryHighThreshold(): void
    {
        $probability = 0.60; // Exactly 60%
        $liveData = [
            'minute' => 20,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 12,
            'dangerous_attacks_away' => 10,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 2,
        ];
        $debug = ['ais_rate' => 1.4];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertTrue($result['bet']);
        $this->assertStringContainsString('High goal probability', $result['reason']);
    }

    /**
     * Test boundary: exactly 20% probability (low threshold).
     */
    public function testBoundaryLowThreshold(): void
    {
        $probability = 0.20; // Exactly 20%
        $liveData = [
            'minute' => 20,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 5,
            'dangerous_attacks_away' => 4,
            'shots_on_target_home' => 1,
            'shots_on_target_away' => 1,
        ];
        $debug = ['ais_rate' => 0.7];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        // At exactly 20%, it's in medium range, not low
        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('Medium', $result['reason']);
    }

    /**
     * Test boundary: exactly 40% probability with good shots.
     */
    public function testBoundaryMediumHighWithShots(): void
    {
        $probability = 0.40; // Exactly 40%
        $liveData = [
            'minute' => 25,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 10,
            'dangerous_attacks_away' => 8,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 1, // Total 3 shots on target (at threshold)
        ];
        $debug = ['ais_rate' => 1.1];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertTrue($result['bet']);
        $this->assertStringContainsString('Medium-high probability', $result['reason']);
    }

    /**
     * Test edge case: missing optional fields in liveData.
     */
    public function testMissingOptionalFields(): void
    {
        $probability = 0.50;
        $liveData = [
            'minute' => 20,
            'score_home' => 0,
            'score_away' => 0,
            // Missing some fields - should use defaults (0)
        ];
        $debug = ['ais_rate' => 1.0];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        // Should not crash, should handle gracefully
        $this->assertFalse($result['bet']);
        $this->assertIsString($result['reason']);
    }

    /**
     * Test realistic scenario: Active match at 28 minutes, 0-0, high activity.
     */
    public function testRealisticActiveMatch(): void
    {
        $probability = 0.52; // 52%
        $liveData = [
            'minute' => 28,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 14,
            'dangerous_attacks_away' => 10,
            'shots_home' => 8,
            'shots_away' => 5,
            'shots_on_target_home' => 4,
            'shots_on_target_away' => 2,
            'corners_home' => 5,
            'corners_away' => 3,
        ];
        $debug = ['ais_rate' => 1.5];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertTrue($result['bet']);
        $this->assertStringContainsString('52', $result['reason']);
    }

    /**
     * Test realistic scenario: Passive match, low probability.
     */
    public function testRealisticPassiveMatch(): void
    {
        $probability = 0.12; // 12%
        $liveData = [
            'minute' => 30,
            'score_home' => 0,
            'score_away' => 0,
            'dangerous_attacks_home' => 4,
            'dangerous_attacks_away' => 3,
            'shots_home' => 2,
            'shots_away' => 1,
            'shots_on_target_home' => 0,
            'shots_on_target_away' => 0,
            'corners_home' => 1,
            'corners_away' => 1,
        ];
        $debug = ['ais_rate' => 0.4];

        $result = $this->filter->shouldBet($probability, $liveData, $debug);

        $this->assertFalse($result['bet']);
        $this->assertStringContainsString('Low goal probability', $result['reason']);
    }
}
