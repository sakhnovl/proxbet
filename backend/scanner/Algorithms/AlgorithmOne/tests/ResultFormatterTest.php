<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\ResultFormatter;

final class ResultFormatterTest extends TestCase
{
    private ResultFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ResultFormatter();
    }

    public function testFormatLegacyVersion(): void
    {
        $base = [
            'match_id' => 12345,
            'country' => 'England',
            'liga' => 'Premier League',
            'home' => 'Arsenal',
            'away' => 'Chelsea',
        ];

        $liveData = [
            'minute' => 22,
            'time_str' => '22:30',
            'match_status' => '1H',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_total' => 10,
            'shots_on_target' => 6,
            'dangerous_attacks' => 35,
            'corners' => 5,
        ];

        $scores = [
            'algorithm_version' => 1,
            'probability' => 0.68,
            'form_score' => 0.7,
            'h2h_score' => 0.5,
            'live_score' => 0.75,
        ];

        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true];
        $decision = ['bet' => true, 'reason' => 'All conditions met'];

        $result = $this->formatter->format($base, $liveData, $scores, $formData, $h2hData, $decision);

        $this->assertSame(12345, $result['match_id']);
        $this->assertSame('England', $result['country']);
        $this->assertSame('Premier League', $result['liga']);
        $this->assertSame('Arsenal', $result['home']);
        $this->assertSame('Chelsea', $result['away']);
        $this->assertSame(22, $result['minute']);
        $this->assertSame('22:30', $result['time']);
        $this->assertSame('1H', $result['match_status']);
        $this->assertSame(0, $result['score_home']);
        $this->assertSame(0, $result['score_away']);
        $this->assertSame(1, $result['algorithm_id']);
        $this->assertSame('Алгоритм 1', $result['algorithm_name']);
        $this->assertSame('first_half_goal', $result['signal_type']);
        $this->assertSame(0.68, $result['probability']);
        $this->assertSame(0.7, $result['form_score']);
        $this->assertSame(0.5, $result['h2h_score']);
        $this->assertSame(0.75, $result['live_score']);
        $this->assertSame(['bet' => true, 'reason' => 'All conditions met'], $result['decision']);
        $this->assertNull($result['algorithm_data']);
    }

    public function testFormatV2Version(): void
    {
        $base = [
            'match_id' => 67890,
            'country' => 'Spain',
            'liga' => 'La Liga',
            'home' => 'Barcelona',
            'away' => 'Real Madrid',
        ];

        $liveData = [
            'minute' => 28,
            'time_str' => '28:15',
            'match_status' => '1H',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_total' => 15,
            'shots_on_target' => 10,
            'dangerous_attacks' => 50,
            'corners' => 7,
        ];

        $scores = [
            'algorithm_version' => 2,
            'probability' => 0.72,
            'form_score' => 0.75,
            'h2h_score' => 0.6,
            'live_score' => 0.8,
            'debug_trace' => [
                'gating_passed' => true,
                'gating_reason' => '',
                'decision_reason' => 'probability_threshold_met',
                'probability' => 0.72,
                'red_flag' => null,
            ],
            'components' => [
                'pdi' => 0.85,
                'shot_quality' => 0.78,
                'trend_acceleration' => 0.65,
                'time_pressure' => 0.87,
                'league_factor' => 1.12,
                'card_factor' => 0.03,
                'xg_pressure' => 0.9,
                'red_flag' => null,
            ],
        ];

        $formData = ['home_goals' => 5, 'away_goals' => 4, 'has_data' => true];
        $h2hData = ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true];
        $decision = ['bet' => true, 'reason' => 'V2 conditions met'];

        $result = $this->formatter->format($base, $liveData, $scores, $formData, $h2hData, $decision);

        $this->assertSame(67890, $result['match_id']);
        $this->assertSame(1, $result['algorithm_id']); // Algorithm ID is always 1, version is in algorithm_data
        $this->assertSame(0.72, $result['probability']);
        $this->assertNotNull($result['algorithm_data']);
        $this->assertSame(2, $result['algorithm_data']['algorithm_version']);
        $this->assertArrayHasKey('components', $result['algorithm_data']);
        $this->assertSame(0.85, $result['algorithm_data']['components']['pdi']);
        $this->assertNull($result['algorithm_data']['red_flag']);
        $this->assertTrue($result['algorithm_data']['gating_passed']);
        $this->assertSame('probability_threshold_met', $result['algorithm_data']['decision_reason']);
        $this->assertSame(0.72, $result['algorithm_data']['probability']);
        $this->assertArrayHasKey('debug_trace', $result['algorithm_data']);
    }

    public function testFormatV2WithRedFlag(): void
    {
        $base = [
            'match_id' => 11111,
            'country' => 'Germany',
            'liga' => 'Bundesliga',
            'home' => 'Bayern',
            'away' => 'Dortmund',
        ];

        $liveData = [
            'minute' => 25,
            'time_str' => '25:00',
            'match_status' => '1H',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_total' => 12,
            'shots_on_target' => 3,
            'dangerous_attacks' => 40,
            'corners' => 6,
        ];

        $scores = [
            'algorithm_version' => 2,
            'probability' => 0.45,
            'form_score' => 0.7,
            'h2h_score' => 0.5,
            'live_score' => 0.4,
            'debug_trace' => [
                'gating_passed' => true,
                'gating_reason' => '',
                'decision_reason' => 'red_flag_low_accuracy',
                'probability' => 0.45,
                'red_flag' => 'low_accuracy',
            ],
            'components' => [
                'pdi' => 0.75,
                'shot_quality' => 0.3,
                'red_flag' => 'low_accuracy',
            ],
        ];

        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true];
        $decision = ['bet' => false, 'reason' => 'Red flag: low_accuracy'];

        $result = $this->formatter->format($base, $liveData, $scores, $formData, $h2hData, $decision);

        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('low_accuracy', $result['algorithm_data']['red_flag']);
        $this->assertSame('low_accuracy', $result['algorithm_data']['components']['red_flag']);
    }

    public function testFormatWithDualRun(): void
    {
        $base = [
            'match_id' => 22222,
            'country' => 'Italy',
            'liga' => 'Serie A',
            'home' => 'Juventus',
            'away' => 'Inter',
        ];

        $liveData = [
            'minute' => 27,
            'time_str' => '27:00',
            'match_status' => '1H',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_total' => 13,
            'shots_on_target' => 8,
            'dangerous_attacks' => 45,
            'corners' => 6,
        ];

        $scores = [
            'algorithm_version' => 2,
            'probability' => 0.7,
            'form_score' => 0.72,
            'h2h_score' => 0.55,
            'live_score' => 0.78,
            'debug_trace' => [
                'gating_passed' => true,
                'gating_reason' => '',
                'decision_reason' => 'probability_threshold_met',
                'probability' => 0.7,
                'red_flag' => null,
            ],
            'components' => [
                'pdi' => 0.8,
                'shot_quality' => 0.75,
                'red_flag' => null,
            ],
        ];

        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true];
        $decision = ['bet' => true, 'reason' => 'V2 conditions met'];

        $legacyScores = [
            'probability' => 0.62,
            'form_score' => 0.7,
            'h2h_score' => 0.5,
            'live_score' => 0.65,
        ];

        $v2Scores = [
            'probability' => 0.7,
            'form_score' => 0.72,
            'h2h_score' => 0.55,
            'live_score' => 0.78,
        ];

        $result = $this->formatter->format(
            $base,
            $liveData,
            $scores,
            $formData,
            $h2hData,
            $decision,
            $legacyScores,
            $v2Scores
        );

        $this->assertArrayHasKey('dual_run', $result['algorithm_data']);
        $this->assertSame(0.62, $result['algorithm_data']['dual_run']['legacy_probability']);
        $this->assertSame(0.7, $result['algorithm_data']['dual_run']['v2_probability']);
        $this->assertEqualsWithDelta(0.08, $result['algorithm_data']['dual_run']['probability_diff'], 0.0001);
    }

    public function testFormatLegacyPrimaryWithDualRunStillExposesAlgorithmData(): void
    {
        $base = [
            'match_id' => 22223,
            'country' => 'Italy',
            'liga' => 'Serie A',
            'home' => 'Roma',
            'away' => 'Lazio',
        ];

        $liveData = [
            'minute' => 24,
            'time_str' => '24:00',
            'match_status' => '1H',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_total' => 11,
            'shots_on_target' => 5,
            'dangerous_attacks' => 37,
            'corners' => 4,
        ];

        $scores = [
            'algorithm_version' => 1,
            'probability' => 0.63,
            'form_score' => 0.69,
            'h2h_score' => 0.55,
            'live_score' => 0.71,
            'debug_trace' => [
                'gating_passed' => true,
                'gating_reason' => '',
                'decision_reason' => 'legacy_threshold_met',
                'probability' => 0.63,
            ],
            'dual_run' => [
                'primary_version' => 1,
                'legacy_probability' => 0.63,
                'legacy_decision' => 'bet',
                'v2_probability' => 0.57,
                'v2_decision' => 'no_bet',
                'probability_diff' => 0.06,
                'decision_match' => false,
                'divergence_level' => 'high',
            ],
        ];

        $formData = ['home_goals' => 4, 'away_goals' => 2, 'has_data' => true];
        $h2hData = ['home_goals' => 3, 'away_goals' => 1, 'has_data' => true];
        $decision = ['bet' => true, 'reason' => 'Legacy conditions met'];

        $result = $this->formatter->format($base, $liveData, $scores, $formData, $h2hData, $decision);

        $this->assertSame(1, $result['algorithm_data']['algorithm_version']);
        $this->assertSame('bet', $result['algorithm_data']['dual_run']['legacy_decision']);
        $this->assertSame('no_bet', $result['algorithm_data']['dual_run']['v2_decision']);
        $this->assertSame('high', $result['algorithm_data']['dual_run']['divergence_level']);
    }

    public function testFormatStatsSection(): void
    {
        $base = [
            'match_id' => 33333,
            'country' => 'France',
            'liga' => 'Ligue 1',
            'home' => 'PSG',
            'away' => 'Lyon',
        ];

        $liveData = [
            'minute' => 20,
            'time_str' => '20:00',
            'match_status' => '1H',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_total' => 8,
            'shots_on_target' => 5,
            'dangerous_attacks' => 30,
            'corners' => 4,
        ];

        $scores = [
            'algorithm_version' => 1,
            'probability' => 0.6,
            'form_score' => 0.65,
            'h2h_score' => 0.5,
            'live_score' => 0.7,
        ];

        $formData = ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true];
        $h2hData = ['home_goals' => 1, 'away_goals' => 1, 'has_data' => true];
        $decision = ['bet' => true, 'reason' => 'Conditions met'];

        $result = $this->formatter->format($base, $liveData, $scores, $formData, $h2hData, $decision);

        $this->assertArrayHasKey('stats', $result);
        $this->assertSame(8, $result['stats']['shots_total']);
        $this->assertSame(5, $result['stats']['shots_on_target']);
        $this->assertSame(30, $result['stats']['dangerous_attacks']);
        $this->assertSame(4, $result['stats']['corners']);
    }

    public function testFormatFormAndH2hData(): void
    {
        $base = [
            'match_id' => 44444,
            'country' => 'Portugal',
            'liga' => 'Primeira Liga',
            'home' => 'Benfica',
            'away' => 'Porto',
        ];

        $liveData = [
            'minute' => 23,
            'time_str' => '23:00',
            'match_status' => '1H',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_total' => 9,
            'shots_on_target' => 6,
            'dangerous_attacks' => 32,
            'corners' => 5,
        ];

        $scores = [
            'algorithm_version' => 1,
            'probability' => 0.65,
            'form_score' => 0.7,
            'h2h_score' => 0.6,
            'live_score' => 0.72,
        ];

        $formData = ['home_goals' => 5, 'away_goals' => 4, 'has_data' => true];
        $h2hData = ['home_goals' => 3, 'away_goals' => 1, 'has_data' => true];
        $decision = ['bet' => true, 'reason' => 'All conditions met'];

        $result = $this->formatter->format($base, $liveData, $scores, $formData, $h2hData, $decision);

        $this->assertArrayHasKey('form_data', $result);
        $this->assertSame(5, $result['form_data']['home_goals']);
        $this->assertSame(4, $result['form_data']['away_goals']);

        $this->assertArrayHasKey('h2h_data', $result);
        $this->assertSame(3, $result['h2h_data']['home_goals']);
        $this->assertSame(1, $result['h2h_data']['away_goals']);
    }

    public function testFormatDecisionRejected(): void
    {
        $base = [
            'match_id' => 55555,
            'country' => 'Netherlands',
            'liga' => 'Eredivisie',
            'home' => 'Ajax',
            'away' => 'PSV',
        ];

        $liveData = [
            'minute' => 18,
            'time_str' => '18:00',
            'match_status' => '1H',
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'shots_total' => 5,
            'shots_on_target' => 2,
            'dangerous_attacks' => 15,
            'corners' => 2,
        ];

        $scores = [
            'algorithm_version' => 1,
            'probability' => 0.42,
            'form_score' => 0.5,
            'h2h_score' => 0.4,
            'live_score' => 0.35,
        ];

        $formData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];
        $h2hData = ['home_goals' => 1, 'away_goals' => 1, 'has_data' => true];
        $decision = ['bet' => false, 'reason' => 'Probability too low: 0.42 < 0.55'];

        $result = $this->formatter->format($base, $liveData, $scores, $formData, $h2hData, $decision);

        $this->assertFalse($result['decision']['bet']);
        $this->assertStringContainsString('Probability too low', $result['decision']['reason']);
    }
}
