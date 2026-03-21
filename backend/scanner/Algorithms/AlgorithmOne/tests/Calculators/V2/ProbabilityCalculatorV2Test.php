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
        $this->assertFalse($result['debug']['gating_passed']);
        $this->assertSame('no_form_data', $result['debug']['gating_reason']);
    }

    public function testFallsBackWhenNoH2hData(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => false];
        $liveData = [
            'league_category' => 'top-tier',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 4,
            'shots_total' => 8,
            'dangerous_attacks' => 38,
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 18,
            'corners' => 3,
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 20);

        $this->assertGreaterThan(0.0, $result['probability']);
        $this->assertSame(0.94, $result['components']['penalties']['missing_h2h']);
        $this->assertFalse($result['debug']['gating_context']['has_h2h_data']);
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

    public function testRejectsWhenInsufficientShotsOnTargetOutsideEarlyReliefWindow(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => true, 'home_goals' => 2, 'away_goals' => 2];
        $liveData = [
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 0,
            'shots_total' => 6,
            'dangerous_attacks' => 40,
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 22);

        $this->assertSame(0.0, $result['probability']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('insufficient_shots_on_target', $result['decision']['reason']);
    }

    public function testAllowsEarlyWindowWithoutShotsOnTargetWhenPressureSignalsAreStrong(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => true, 'home_goals' => 2, 'away_goals' => 2];
        $liveData = [
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 0,
            'shots_total' => 7,
            'dangerous_attacks' => 38,
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 18,
            'corners' => 4,
            'has_trend_data' => true,
            'trend_window_seconds' => 300,
            'trend_shots_total_delta' => 7,
            'trend_dangerous_attacks_delta' => 14,
            'trend_xg_delta' => 0.2,
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 18);

        $this->assertGreaterThan(0.0, $result['probability']);
        $this->assertTrue($result['debug']['gating_context']['shots_gate_relief']);
        $this->assertSame(0.91, $result['components']['penalties']['early_shots_relief']);
    }

    public function testConvertsLowAccuracyToPenalty(): void
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

        $this->assertGreaterThan(0.0, $result['probability']);
        $this->assertTrue($result['debug']['gating_passed']);
        $this->assertSame('low_accuracy', $result['debug']['red_flag']);
        $this->assertSame('low_accuracy', $result['components']['red_flag']);
        $this->assertContains('low_accuracy', $result['debug']['red_flags']);
        $this->assertSame(0.88, $result['components']['penalties']['low_accuracy']);
    }

    public function testConvertsAttackTempoToPenalty(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => true, 'home_goals' => 2, 'away_goals' => 2];
        $liveData = [
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 3,
            'shots_total' => 7,
            'dangerous_attacks' => 25,
            'dangerous_attacks_home' => 13,
            'dangerous_attacks_away' => 12,
            'corners' => 2,
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 20);

        $this->assertGreaterThan(0.0, $result['probability']);
        $this->assertArrayHasKey('soft_attack_tempo', $result['components']['penalties']);
        $this->assertLessThan(1.0, $result['components']['penalties']['soft_attack_tempo']);
        $this->assertLessThan(1.5, $result['debug']['gating_context']['attack_tempo']);
    }

    public function testLeagueCategoryAdjustsMissingH2hPenalty(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => false];

        $topTier = $this->calculator->calculate($formData, $h2hData, [
            'league_category' => 'top-tier',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 4,
            'shots_total' => 9,
            'dangerous_attacks' => 36,
        ], 20);

        $women = $this->calculator->calculate($formData, $h2hData, [
            'league_category' => 'women',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 4,
            'shots_total' => 9,
            'dangerous_attacks' => 36,
        ], 20);

        $this->assertSame(0.94, $topTier['components']['penalties']['missing_h2h']);
        $this->assertArrayNotHasKey('missing_h2h', $women['components']['penalties']);
    }

    public function testLeagueCategoryAdjustsAttackTempoThreshold(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $h2hData = ['has_data' => true, 'home_goals' => 2, 'away_goals' => 2];
        $baseLiveData = [
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 3,
            'shots_total' => 8,
            'dangerous_attacks' => 26,
            'dangerous_attacks_home' => 13,
            'dangerous_attacks_away' => 13,
            'corners' => 2,
        ];

        $topTier = $this->calculator->calculate($formData, $h2hData, $baseLiveData + [
            'league_category' => 'top-tier',
        ], 20);
        $youth = $this->calculator->calculate($formData, $h2hData, $baseLiveData + [
            'league_category' => 'youth',
        ], 20);

        $this->assertArrayHasKey('soft_attack_tempo', $topTier['components']['penalties']);
        $this->assertArrayNotHasKey('soft_attack_tempo', $youth['components']['penalties']);
    }

    public function testConvertsIneffectivePressureToPenalty(): void
    {
        $formData = ['has_data' => true, 'home_goals' => 4, 'away_goals' => 3];
        $h2hData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $liveData = [
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 2,
            'shots_total' => 7,
            'dangerous_attacks' => 40,
            'dangerous_attacks_home' => 34,
            'dangerous_attacks_away' => 6,
            'shots_on_target_home' => 1,
            'shots_on_target_away' => 1,
            'corners' => 4,
        ];

        $result = $this->calculator->calculate($formData, $h2hData, $liveData, 24);

        $this->assertContains('ineffective_pressure', $result['debug']['red_flags']);
        $this->assertSame(0.90, $result['components']['penalties']['ineffective_pressure']);
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
        $this->assertArrayHasKey('debug', $result);
        $this->assertTrue($result['debug']['gating_passed']);
        $this->assertSame($result['decision']['reason'], $result['debug']['decision_reason']);
        $this->assertArrayHasKey('penalties', $result['debug']);
        $this->assertArrayHasKey('gating_context', $result['debug']);
        $this->assertArrayHasKey('probability_breakdown', $result['components']);
        $this->assertArrayHasKey('component_contributions', $result['components']);
        $this->assertArrayHasKey('threshold_evaluation', $result['components']);
        $this->assertArrayHasKey('live_components_final', $result['components']['component_contributions']);
    }

    public function testUsesConfigurableThresholdCandidatesInDebugPayload(): void
    {
        $_ENV['ALGORITHM1_V2_MIN_PROBABILITY'] = '0.52';
        $_ENV['ALGORITHM1_V2_THRESHOLD_CANDIDATES'] = '0.55,0.52,0.50';

        $formData = [
            'has_data' => true,
            'home_goals' => 4,
            'away_goals' => 3,
            'weighted' => [
                'score' => 0.75,
            ],
        ];
        $h2hData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];
        $liveData = [
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 5,
            'shots_total' => 10,
            'dangerous_attacks' => 40,
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 20,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 2,
            'xg_home' => 1.0,
            'xg_away' => 0.9,
            'table_avg' => 2.4,
        ];

        try {
            $result = $this->calculator->calculate($formData, $h2hData, $liveData, 24);

            $this->assertArrayHasKey('0.55', $result['components']['threshold_evaluation']['candidates']);
            $this->assertSame(0.52, $result['components']['threshold_evaluation']['active']);
        } finally {
            unset($_ENV['ALGORITHM1_V2_MIN_PROBABILITY'], $_ENV['ALGORITHM1_V2_THRESHOLD_CANDIDATES']);
        }
    }

    public function testUsesLeagueSpecificThresholdInDebugPayload(): void
    {
        $formData = [
            'has_data' => true,
            'home_goals' => 4,
            'away_goals' => 3,
            'weighted' => [
                'score' => 0.75,
            ],
        ];
        $h2hData = ['has_data' => true, 'home_goals' => 3, 'away_goals' => 2];

        $result = $this->calculator->calculate($formData, $h2hData, [
            'league_category' => 'youth',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_on_target' => 5,
            'shots_total' => 10,
            'dangerous_attacks' => 40,
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 20,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 2,
            'xg_home' => 1.0,
            'xg_away' => 0.9,
            'table_avg' => 2.4,
        ], 24);

        $this->assertSame('youth', $result['components']['league_segment']);
        $this->assertSame(0.49, $result['components']['threshold_evaluation']['active']);
        $this->assertSame(0.49, $result['components']['league_profile']['probability_threshold']);
    }
}
