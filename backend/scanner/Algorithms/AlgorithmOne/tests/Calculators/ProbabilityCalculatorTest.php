<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\FormScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\H2hScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\LiveScoreCalculator;

final class ProbabilityCalculatorTest extends TestCase
{
    private ProbabilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProbabilityCalculator();
    }

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

    private function buildLiveData(): array
    {
        return [
            'minute' => 20,
            'shots_total' => 8,
            'shots_on_target' => 4,
            'dangerous_attacks' => 28,
            'corners' => 4,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 2,
            'shots_off_target_home' => 2,
            'shots_off_target_away' => 2,
            'dangerous_attacks_home' => 14,
            'dangerous_attacks_away' => 14,
            'corners_home' => 2,
            'corners_away' => 2,
            'xg_home' => null,
            'xg_away' => null,
            'yellow_cards_home' => null,
            'yellow_cards_away' => null,
            'trend_shots_total_delta' => null,
            'trend_shots_on_target_delta' => null,
            'trend_dangerous_attacks_delta' => null,
            'trend_xg_delta' => null,
            'trend_window_seconds' => null,
            'has_trend_data' => false,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'time_str' => '20:00',
            'match_status' => 'HT',
        ];
    }

    public function testCalculateReturnsAllScores(): void
    {
        $formData = $this->buildFormData(4, 2);
        $h2hData = $this->buildH2hData(3, 1);
        $liveData = $this->buildLiveData();

        $result = $this->calculator->calculate($formData, $h2hData, $liveData);

        $this->assertArrayHasKey('form_score', $result);
        $this->assertArrayHasKey('h2h_score', $result);
        $this->assertArrayHasKey('live_score', $result);
        $this->assertArrayHasKey('probability', $result);
    }

    public function testProbabilityFormulaIsCorrect(): void
    {
        // Use real calculators with controlled data to verify formula
        // Form: (3/5 + 2/5) / 2 = 0.5
        $formData = $this->buildFormData(3, 2);
        
        // H2H: (2/5 + 2/5) / 2 = 0.4
        $h2hData = $this->buildH2hData(2, 2);
        
        // Live: will produce a known score based on the data
        $liveData = $this->buildLiveData();

        $result = $this->calculator->calculate($formData, $h2hData, $liveData);

        // Verify formula: form * 0.35 + h2h * 0.15 + live * 0.50
        $expected = $result['form_score'] * 0.35 + $result['h2h_score'] * 0.15 + $result['live_score'] * 0.50;
        $this->assertEqualsWithDelta($expected, $result['probability'], 0.001);
    }

    public function testProbabilityIsInValidRange(): void
    {
        $formData = $this->buildFormData(5, 5);
        $h2hData = $this->buildH2hData(5, 5);
        $liveData = $this->buildLiveData();

        $result = $this->calculator->calculate($formData, $h2hData, $liveData);

        $this->assertGreaterThanOrEqual(0.0, $result['probability']);
        $this->assertLessThanOrEqual(1.0, $result['probability']);
    }

    public function testZeroScoresProduceZeroProbability(): void
    {
        // Use data that produces zero scores
        $formData = ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false];
        $h2hData = ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false];
        $liveData = [
            'minute' => 20,
            'shots_total' => 0,
            'shots_on_target' => 0,
            'dangerous_attacks' => 0,
            'corners' => 0,
            'shots_on_target_home' => 0,
            'shots_on_target_away' => 0,
            'shots_off_target_home' => 0,
            'shots_off_target_away' => 0,
            'dangerous_attacks_home' => 0,
            'dangerous_attacks_away' => 0,
            'corners_home' => 0,
            'corners_away' => 0,
            'xg_home' => null,
            'xg_away' => null,
            'yellow_cards_home' => null,
            'yellow_cards_away' => null,
            'trend_shots_total_delta' => null,
            'trend_shots_on_target_delta' => null,
            'trend_dangerous_attacks_delta' => null,
            'trend_xg_delta' => null,
            'trend_window_seconds' => null,
            'has_trend_data' => false,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'time_str' => '20:00',
            'match_status' => 'HT',
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData);

        $this->assertSame(0.0, $result['probability']);
    }

    public function testMaxScoresProduceMaxProbability(): void
    {
        // Use data that produces maximum scores
        $formData = $this->buildFormData(5, 5); // (5/5 + 5/5) / 2 = 1.0
        $h2hData = $this->buildH2hData(5, 5); // (5/5 + 5/5) / 2 = 1.0
        
        // Build live data with maximum activity
        $liveData = [
            'minute' => 20,
            'shots_total' => 50,
            'shots_on_target' => 40,
            'dangerous_attacks' => 80,
            'corners' => 20,
            'shots_on_target_home' => 20,
            'shots_on_target_away' => 20,
            'shots_off_target_home' => 5,
            'shots_off_target_away' => 5,
            'dangerous_attacks_home' => 40,
            'dangerous_attacks_away' => 40,
            'corners_home' => 10,
            'corners_away' => 10,
            'xg_home' => 3.0,
            'xg_away' => 3.0,
            'yellow_cards_home' => 0,
            'yellow_cards_away' => 0,
            'trend_shots_total_delta' => 20,
            'trend_shots_on_target_delta' => 15,
            'trend_dangerous_attacks_delta' => 30,
            'trend_xg_delta' => 1.5,
            'trend_window_seconds' => 300,
            'has_trend_data' => true,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'time_str' => '20:00',
            'match_status' => 'HT',
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData);

        // With maximum data, probability should be very high (close to 1.0)
        $this->assertGreaterThan(0.9, $result['probability']);
        $this->assertLessThanOrEqual(1.0, $result['probability']);
    }

    public function testLiveScoreHasHighestWeight(): void
    {
        // Test that live score (0.50 weight) has more impact than form (0.35) or h2h (0.15)
        // Use data that produces zero form and h2h scores but high live score
        $formData = ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false]; // form_score = 0.0
        $h2hData = ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false]; // h2h_score = 0.0
        
        // High activity live data
        $liveData = [
            'minute' => 20,
            'shots_total' => 30,
            'shots_on_target' => 20,
            'dangerous_attacks' => 60,
            'corners' => 10,
            'shots_on_target_home' => 10,
            'shots_on_target_away' => 10,
            'shots_off_target_home' => 5,
            'shots_off_target_away' => 5,
            'dangerous_attacks_home' => 30,
            'dangerous_attacks_away' => 30,
            'corners_home' => 5,
            'corners_away' => 5,
            'xg_home' => 2.0,
            'xg_away' => 2.0,
            'yellow_cards_home' => 0,
            'yellow_cards_away' => 0,
            'trend_shots_total_delta' => 15,
            'trend_shots_on_target_delta' => 10,
            'trend_dangerous_attacks_delta' => 25,
            'trend_xg_delta' => 1.0,
            'trend_window_seconds' => 300,
            'has_trend_data' => true,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'time_str' => '20:00',
            'match_status' => 'HT',
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData);

        // With form=0, h2h=0, only live score contributes
        // Probability should be approximately: 0 * 0.35 + 0 * 0.15 + live_score * 0.50
        $this->assertSame(0.0, $result['form_score']);
        $this->assertSame(0.0, $result['h2h_score']);
        $this->assertGreaterThan(0.5, $result['live_score']); // Live score should be high
        
        // Probability should be roughly half of live_score (due to 0.50 weight)
        $expectedProbability = $result['live_score'] * 0.50;
        $this->assertEqualsWithDelta($expectedProbability, $result['probability'], 0.001);
    }

    public function testIntegrationWithRealCalculators(): void
    {
        $formData = $this->buildFormData(4, 3);
        $h2hData = $this->buildH2hData(2, 2);
        $liveData = $this->buildLiveData();

        $result = $this->calculator->calculate($formData, $h2hData, $liveData);

        // Verify all components are calculated
        $this->assertGreaterThan(0.0, $result['form_score']);
        $this->assertGreaterThan(0.0, $result['h2h_score']);
        $this->assertGreaterThan(0.0, $result['live_score']);
        $this->assertGreaterThan(0.0, $result['probability']);
    }
}
