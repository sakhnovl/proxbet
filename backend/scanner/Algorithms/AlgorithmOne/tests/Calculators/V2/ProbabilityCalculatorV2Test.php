<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators\V2;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ProbabilityCalculatorV2;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\PdiCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ShotQualityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TrendCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TimePressureCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\LeagueFactorCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\CardFactorCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\XgPressureCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\RedFlagChecker;

final class ProbabilityCalculatorV2Test extends TestCase
{
    private ProbabilityCalculatorV2 $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProbabilityCalculatorV2(
            new PdiCalculator(),
            new ShotQualityCalculator(),
            new TrendCalculator(),
            new TimePressureCalculator(),
            new LeagueFactorCalculator(),
            new CardFactorCalculator(),
            new XgPressureCalculator(),
            new RedFlagChecker()
        );
    }

    public function testRejectsWhenNoFormData(): void
    {
        $formData = ['has_data' => false];
        $h2hData = ['has_data' => true];
        $liveData = [];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 20);

        $this->assertSame(0.0, $result['probability']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('no_form_data', $result['decision']['reason']);
    }

    public function testRejectsWhenNoH2hData(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => false];
        $liveData = [];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 20);

        $this->assertSame(0.0, $result['probability']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('no_h2h_data', $result['decision']['reason']);
    }

    public function testRejectsWhenScoreIsNot0_0(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => true, 'home_goals' => 2, 'away_goals' => 2];
        $liveData = ['ht_hscore' => 1, 'ht_ascore' => 0];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 20);

        $this->assertSame(0.0, $result['probability']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('ht_score_not_0_0', $result['decision']['reason']);
    }

    public function testRejectsWhenMinuteOutOfRange(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => true, 'home_goals' => 2, 'away_goals' => 2];
        $liveData = ['ht_hscore' => 0, 'ht_ascore' => 0, 'shots_on_target' => 3];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 35);

        $this->assertSame(0.0, $result['probability']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('minute_out_of_range', $result['decision']['reason']);
    }

    public function testRejectsWhenInsufficientShotsOnTarget(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => true, 'home_goals' => 2, 'away_goals' => 2];
        $liveData = [
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 0,
            'dangerous_attacks' => 40,
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 22);

        $this->assertSame(0.0, $result['probability']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('insufficient_shots_on_target', $result['decision']['reason']);
    }

    public function testRejectsWhenInsufficientAttackTempo(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => true, 'home_goals' => 2, 'away_goals' => 2];
        $liveData = [
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 3,
            'dangerous_attacks' => 25,
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 20);

        // Attack tempo: 25/20 = 1.25 <= 1.5
        $this->assertSame(0.0, $result['probability']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('insufficient_attack_tempo', $result['decision']['reason']);
    }

    public function testRejectsOnLowAccuracyRedFlag(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => true, 'home_goals' => 2, 'away_goals' => 2];
        $liveData = [
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 2,
            'shots_total' => 12,
            'dangerous_attacks' => 40,
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 22);

        $this->assertSame(0.0, $result['probability']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('red_flag_low_accuracy', $result['decision']['reason']);
    }

    public function testCalculatesFullV2Probability(): void
    {
        $formData = [
            'has_data' => true,
            'home_goals' => 4,
            'away_goals' => 3,
            'weighted' => [
                'score' => 0.75,
                'home' => ['attack' => 0.8, 'defense' => 0.3],
                'away' => ['attack' => 0.6, 'defense' => 0.4],
            ],
        ];
        $h2hData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $liveData = [
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 6,
            'shots_total' => 10,
            'dangerous_attacks' => 40,
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 20,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 3,
            'xg_home' => 1.2,
            'xg_away' => 1.0,
            'yellow_cards_home' => 1,
            'yellow_cards_away' => 2,
            'red_cards_home' => 0,
            'red_cards_away' => 0,
            'table_avg' => 2.5,
            'has_trend_data' => true,
            'trend_window_seconds' => 300,
            'trend_shots_total_delta' => 10,
            'trend_dangerous_attacks_delta' => 25,
            'trend_xg_delta' => 0.5,
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 25);

        $this->assertGreaterThan(0.0, $result['probability']);
        $this->assertLessThanOrEqual(1.0, $result['probability']);
        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey('pdi', $result['components']);
        $this->assertArrayHasKey('shot_quality', $result['components']);
        $this->assertArrayHasKey('decision', $result);
    }
}
