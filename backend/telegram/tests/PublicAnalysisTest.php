<?php

declare(strict_types=1);

namespace Proxbet\Telegram\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../public_analysis.php';

final class PublicAnalysisTest extends TestCase
{
    public function testEnrichAnalysisContextWithScannerBuildsAlgorithmOneExplainContextFromSavedPayload(): void
    {
        $context = [
            'algorithm_id' => 1,
            'bet_message_id' => 321,
            'home' => 'Alpha',
            'away' => 'Beta',
            'liga' => 'Premier',
            'country' => 'England',
            'time' => '27:00',
            'match_status' => '1',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'ht_match_goals_1' => 4,
            'ht_match_goals_2' => 3,
            'h2h_ht_match_goals_1' => 2,
            'h2h_ht_match_goals_2' => 2,
            'live_xg_home' => 1.3,
            'live_xg_away' => 0.9,
            'live_att_home' => 42,
            'live_att_away' => 37,
            'live_danger_att_home' => 24,
            'live_danger_att_away' => 21,
            'live_shots_on_target_home' => 4,
            'live_shots_on_target_away' => 3,
            'live_shots_off_target_home' => 5,
            'live_shots_off_target_away' => 4,
            'live_corner_home' => 3,
            'live_corner_away' => 2,
            'algorithm_payload_json' => json_encode([
                'algorithm_version' => 2,
                'components' => [
                    'pdi' => 0.81,
                    'shot_quality' => 0.74,
                    'trend_acceleration' => 0.62,
                    'time_pressure' => 0.58,
                    'probability_breakdown' => [
                        'form_score' => 0.71,
                        'h2h_score' => 0.50,
                        'live_score_adjusted' => 0.83,
                        'pre_penalty_probability' => 0.72,
                        'final_probability' => 0.64,
                        'probability_threshold' => 0.55,
                    ],
                    'threshold_evaluation' => [
                        'active' => 0.55,
                        'candidates' => ['0.55' => true],
                    ],
                ],
                'gating_passed' => true,
                'gating_reason' => '',
                'decision_reason' => 'probability_threshold_met',
                'probability' => 0.64,
                'debug_trace' => [
                    'red_flag' => 'low_accuracy',
                    'red_flags' => ['low_accuracy'],
                    'penalties' => ['low_accuracy' => 0.88],
                    'gating_context' => ['has_h2h_data' => false],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $result = enrichAnalysisContextWithScanner($context);

        $this->assertSame(64, $result['scanner_probability']);
        $this->assertSame('yes', $result['scanner_bet']);
        $this->assertSame('probability_threshold_met', $result['scanner_reason']);
        $this->assertArrayHasKey('algorithm_one_explain_context', $result);
        $this->assertArrayHasKey('scanner_algorithm_data', $result);

        $explainContext = $result['algorithm_one_explain_context'];
        $this->assertSame(2, $explainContext['algorithm_version']);
        $this->assertSame('Premier', $explainContext['league']);
        $this->assertSame(27, $explainContext['minute']);
        $this->assertTrue($explainContext['bet']);
        $this->assertSame(0.64, $explainContext['probability']);
        $this->assertSame('probability_threshold_met', $explainContext['reason']);
        $this->assertSame(['low_accuracy'], $explainContext['red_flags']);
        $this->assertSame(['low_accuracy' => 0.88], $explainContext['penalties']);
        $this->assertSame(['has_h2h_data' => false], $explainContext['gating_context']);
        $this->assertSame(1.3, $explainContext['live_xg_home']);
        $this->assertSame(4, $explainContext['form_home_ht_goals']);

        $scannerAlgorithmData = $result['scanner_algorithm_data'];
        $this->assertSame(2, $scannerAlgorithmData['algorithm_version']);
        $this->assertSame('low_accuracy', $scannerAlgorithmData['red_flag']);
        $this->assertSame(['low_accuracy'], $scannerAlgorithmData['red_flags']);
    }
}
