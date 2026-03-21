<?php

declare(strict_types=1);

namespace Proxbet\Statistic\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Statistic\HtMetricsCalculator;

/**
 * Tests for HtMetricsCalculator including Algorithm 1 v2 weighted form.
 */
final class HtMetricsCalculatorTest extends TestCase
{
    private HtMetricsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new HtMetricsCalculator();
    }

    /**
     * Test backward compatibility: legacy metrics still calculated correctly.
     */
    public function testLegacyMetricsBackwardCompatibility(): void
    {
        $sgi = [
            'Q' => [
                'H' => [
                    $this->createMatch('Team A', 'Opponent 1', 1, 0),
                    $this->createMatch('Team A', 'Opponent 2', 0, 1),
                    $this->createMatch('Team A', 'Opponent 3', 2, 1),
                    $this->createMatch('Team A', 'Opponent 4', 1, 0),
                    $this->createMatch('Team A', 'Opponent 5', 0, 0),
                ],
                'A' => [
                    $this->createMatch('Opponent 1', 'Team B', 0, 1),
                    $this->createMatch('Opponent 2', 'Team B', 1, 1),
                    $this->createMatch('Opponent 3', 'Team B', 0, 0),
                    $this->createMatch('Opponent 4', 'Team B', 2, 1),
                    $this->createMatch('Opponent 5', 'Team B', 1, 0),
                ],
            ],
        ];

        $result = $this->calculator->calculate($sgi, 'Team A', 'Team B');

        // Verify legacy metrics structure is preserved
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('debug', $result);

        // Verify all legacy metric keys exist
        $metrics = $result['metrics'];
        $this->assertArrayHasKey('ht_match_goals_1', $metrics);
        $this->assertArrayHasKey('ht_match_missed_goals_1', $metrics);
        $this->assertArrayHasKey('ht_match_goals_1_avg', $metrics);
        $this->assertArrayHasKey('ht_match_missed_1_avg', $metrics);
        $this->assertArrayHasKey('ht_match_goals_2', $metrics);
        $this->assertArrayHasKey('ht_match_missed_goals_2', $metrics);

        // Verify legacy calculations
        $this->assertSame(3, $metrics['ht_match_goals_1']); // 3 matches with goals
        $this->assertSame(2, $metrics['ht_match_missed_goals_1']); // 2 matches conceded
        $this->assertSame(3, $metrics['ht_match_goals_2']); // 3 matches with goals
        $this->assertSame(2, $metrics['ht_match_missed_goals_2']); // 2 matches conceded
    }

    /**
     * Test weighted form calculation with correct weights.
     */
    public function testWeightedFormCalculation(): void
    {
        $sgi = [
            'Q' => [
                'H' => [
                    $this->createMatch('Team A', 'Opp 1', 2, 1), // weight 0.35: attack=0.70, defense=0.35
                    $this->createMatch('Team A', 'Opp 2', 1, 0), // weight 0.28: attack=0.28, defense=0.00
                    $this->createMatch('Team A', 'Opp 3', 1, 1), // weight 0.20: attack=0.20, defense=0.20
                    $this->createMatch('Team A', 'Opp 4', 0, 0), // weight 0.12: attack=0.00, defense=0.00
                    $this->createMatch('Team A', 'Opp 5', 1, 2), // weight 0.05: attack=0.05, defense=0.10
                ],
                'A' => [
                    $this->createMatch('Opp 1', 'Team B', 0, 1), // weight 0.35: attack=0.35, defense=0.00
                    $this->createMatch('Opp 2', 'Team B', 1, 1), // weight 0.28: attack=0.28, defense=0.28
                    $this->createMatch('Opp 3', 'Team B', 0, 0), // weight 0.20: attack=0.00, defense=0.00
                    $this->createMatch('Opp 4', 'Team B', 2, 0), // weight 0.12: attack=0.00, defense=0.24
                    $this->createMatch('Opp 5', 'Team B', 1, 1), // weight 0.05: attack=0.05, defense=0.05
                ],
            ],
        ];

        $result = $this->calculator->calculate($sgi, 'Team A', 'Team B');

        // Verify v2 debug structure exists
        $this->assertArrayHasKey('algorithm1_v2', $result['debug']);
        $v2 = $result['debug']['algorithm1_v2'];
        $this->assertArrayHasKey('form', $v2);

        // Verify home team weighted metrics
        $homeForm = $v2['form']['home'];
        $this->assertArrayHasKey('attack', $homeForm);
        $this->assertArrayHasKey('defense', $homeForm);
        
        // Expected home attack: 2*0.35 + 1*0.28 + 1*0.20 + 0*0.12 + 1*0.05 = 0.70 + 0.28 + 0.20 + 0.05 = 1.23
        $this->assertEqualsWithDelta(1.23, $homeForm['attack'], 0.01);
        
        // Expected home defense: 1*0.35 + 0*0.28 + 1*0.20 + 0*0.12 + 2*0.05 = 0.35 + 0.20 + 0.10 = 0.65
        $this->assertEqualsWithDelta(0.65, $homeForm['defense'], 0.01);

        // Verify away team weighted metrics
        $awayForm = $v2['form']['away'];
        
        // Expected away attack: 1*0.35 + 1*0.28 + 0*0.20 + 0*0.12 + 1*0.05 = 0.35 + 0.28 + 0.05 = 0.68
        $this->assertEqualsWithDelta(0.68, $awayForm['attack'], 0.01);
        
        // Expected away defense: 0*0.35 + 1*0.28 + 0*0.20 + 2*0.12 + 1*0.05 = 0.28 + 0.24 + 0.05 = 0.57
        $this->assertEqualsWithDelta(0.57, $awayForm['defense'], 0.01);
    }

    /**
     * Test weighted form score formula.
     */
    public function testWeightedFormScore(): void
    {
        $sgi = [
            'Q' => [
                'H' => [
                    $this->createMatch('Home', 'Opp', 1, 0),
                    $this->createMatch('Home', 'Opp', 1, 0),
                    $this->createMatch('Home', 'Opp', 0, 0),
                    $this->createMatch('Home', 'Opp', 0, 0),
                    $this->createMatch('Home', 'Opp', 0, 0),
                ],
                'A' => [
                    $this->createMatch('Opp', 'Away', 0, 1),
                    $this->createMatch('Opp', 'Away', 0, 1),
                    $this->createMatch('Opp', 'Away', 0, 0),
                    $this->createMatch('Opp', 'Away', 0, 0),
                    $this->createMatch('Opp', 'Away', 0, 0),
                ],
            ],
        ];

        $result = $this->calculator->calculate($sgi, 'Home', 'Away');
        $v2 = $result['debug']['algorithm1_v2'];

        // Verify weighted_score is calculated
        $this->assertArrayHasKey('weighted_score', $v2['form']);
        $this->assertIsFloat($v2['form']['weighted_score']);

        // Formula: (home_attack * 0.6 + away_defense * 0.4 + away_attack * 0.6 + home_defense * 0.4) / 2
        $homeAttack = $v2['form']['home']['attack'];
        $homeDefense = $v2['form']['home']['defense'];
        $awayAttack = $v2['form']['away']['attack'];
        $awayDefense = $v2['form']['away']['defense'];

        $expectedScore = (
            $homeAttack * 0.6 +
            $awayDefense * 0.4 +
            $awayAttack * 0.6 +
            $homeDefense * 0.4
        ) / 2.0;

        $this->assertEqualsWithDelta($expectedScore, $v2['form']['weighted_score'], 0.01);
    }

    /**
     * Test handling of empty match lists.
     */
    public function testEmptyMatchLists(): void
    {
        $sgi = ['Q' => ['H' => [], 'A' => []]];

        $result = $this->calculator->calculate($sgi, 'Team A', 'Team B');

        // Legacy metrics should be null
        $this->assertNull($result['metrics']['ht_match_goals_1']);
        $this->assertNull($result['metrics']['ht_match_goals_2']);

        // V2 metrics should be null
        $v2 = $result['debug']['algorithm1_v2']['form'];
        $this->assertNull($v2['home']['attack']);
        $this->assertNull($v2['home']['defense']);
        $this->assertNull($v2['away']['attack']);
        $this->assertNull($v2['away']['defense']);
        $this->assertNull($v2['weighted_score']);
    }

    /**
     * Test handling of invalid match data.
     */
    public function testInvalidMatchData(): void
    {
        $sgi = [
            'Q' => [
                'H' => [
                    'invalid_string',
                    ['missing_required_fields' => true],
                    $this->createMatch('Team A', 'Opp', 1, 0), // Only 1 valid match
                ],
                'A' => [
                    $this->createMatch('Opp', 'Team B', 0, 1),
                ],
            ],
        ];

        $result = $this->calculator->calculate($sgi, 'Team A', 'Team B');

        // Should handle invalid data gracefully
        $this->assertIsArray($result['metrics']);
        $this->assertIsArray($result['debug']);
        
        // V2 should still calculate with valid matches
        $v2 = $result['debug']['algorithm1_v2']['form'];
        $this->assertIsFloat($v2['home']['attack']);
        $this->assertIsFloat($v2['away']['attack']);
    }

    /**
     * Test with less than 5 matches.
     */
    public function testFewerThanFiveMatches(): void
    {
        $sgi = [
            'Q' => [
                'H' => [
                    $this->createMatch('Team A', 'Opp 1', 2, 0), // weight 0.35
                    $this->createMatch('Team A', 'Opp 2', 1, 1), // weight 0.28
                    $this->createMatch('Team A', 'Opp 3', 1, 0), // weight 0.20
                    // Only 3 matches
                ],
                'A' => [
                    $this->createMatch('Opp 1', 'Team B', 0, 1), // weight 0.35
                    $this->createMatch('Opp 2', 'Team B', 1, 0), // weight 0.28
                    // Only 2 matches
                ],
            ],
        ];

        $result = $this->calculator->calculate($sgi, 'Team A', 'Team B');
        $v2 = $result['debug']['algorithm1_v2']['form'];

        // Should calculate with available matches
        $this->assertIsFloat($v2['home']['attack']);
        $this->assertIsFloat($v2['away']['attack']);
        
        // Home: 2*0.35 + 1*0.28 + 1*0.20 = 0.70 + 0.28 + 0.20 = 1.18
        $this->assertEqualsWithDelta(1.18, $v2['form']['home']['attack'], 0.01);
        
        // Away: 1*0.35 + 0*0.28 = 0.35
        $this->assertEqualsWithDelta(0.35, $v2['form']['away']['attack'], 0.01);
    }

    /**
     * Test with all zero scores.
     */
    public function testAllZeroScores(): void
    {
        $sgi = [
            'Q' => [
                'H' => [
                    $this->createMatch('Team A', 'Opp', 0, 0),
                    $this->createMatch('Team A', 'Opp', 0, 0),
                    $this->createMatch('Team A', 'Opp', 0, 0),
                    $this->createMatch('Team A', 'Opp', 0, 0),
                    $this->createMatch('Team A', 'Opp', 0, 0),
                ],
                'A' => [
                    $this->createMatch('Opp', 'Team B', 0, 0),
                    $this->createMatch('Opp', 'Team B', 0, 0),
                    $this->createMatch('Opp', 'Team B', 0, 0),
                    $this->createMatch('Opp', 'Team B', 0, 0),
                    $this->createMatch('Opp', 'Team B', 0, 0),
                ],
            ],
        ];

        $result = $this->calculator->calculate($sgi, 'Team A', 'Team B');
        $v2 = $result['debug']['algorithm1_v2']['form'];

        // All metrics should be 0.0
        $this->assertSame(0.0, $v2['home']['attack']);
        $this->assertSame(0.0, $v2['home']['defense']);
        $this->assertSame(0.0, $v2['away']['attack']);
        $this->assertSame(0.0, $v2['away']['defense']);
        $this->assertSame(0.0, $v2['weighted_score']);
    }

    /**
     * Test high-scoring matches.
     */
    public function testHighScoringMatches(): void
    {
        $sgi = [
            'Q' => [
                'H' => [
                    $this->createMatch('Team A', 'Opp', 3, 2),
                    $this->createMatch('Team A', 'Opp', 2, 3),
                    $this->createMatch('Team A', 'Opp', 4, 1),
                    $this->createMatch('Team A', 'Opp', 2, 2),
                    $this->createMatch('Team A', 'Opp', 1, 3),
                ],
                'A' => [
                    $this->createMatch('Opp', 'Team B', 2, 2),
                    $this->createMatch('Opp', 'Team B', 1, 3),
                    $this->createMatch('Opp', 'Team B', 3, 1),
                    $this->createMatch('Opp', 'Team B', 2, 2),
                    $this->createMatch('Opp', 'Team B', 0, 4),
                ],
            ],
        ];

        $result = $this->calculator->calculate($sgi, 'Team A', 'Team B');
        $v2 = $result['debug']['algorithm1_v2']['form'];

        // Verify calculations work with high scores
        $this->assertGreaterThan(1.0, $v2['home']['attack']);
        $this->assertGreaterThan(1.0, $v2['home']['defense']);
        $this->assertGreaterThan(1.0, $v2['away']['attack']);
        $this->assertGreaterThan(1.0, $v2['away']['defense']);
        $this->assertGreaterThan(1.0, $v2['weighted_score']);
    }

    /**
     * Helper to create a match structure.
     */
    private function createMatch(string $home, string $away, int $htHome, int $htAway): array
    {
        return [
            'H' => ['T' => $home],
            'A' => ['T' => $away],
            'P' => [
                ['H' => $htHome, 'A' => $htAway], // HT score
            ],
        ];
    }
}
