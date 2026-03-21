<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\ResultFormatter;

final class ResultFormatterTest extends TestCase
{
    public function testFormatAlgorithmOneIncludesV2DebugPayload(): void
    {
        $formatter = new ResultFormatter();

        $result = $formatter->formatAlgorithmOne(
            [
                'match_id' => 101,
                'country' => 'Testland',
                'liga' => 'Debug League',
                'home' => 'Home',
                'away' => 'Away',
            ],
            [
                'minute' => 22,
                'time_str' => '22:00',
                'match_status' => '1H',
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'shots_total' => 8,
                'shots_on_target' => 3,
                'dangerous_attacks' => 24,
                'corners' => 4,
            ],
            [
                'algorithm_version' => 2,
                'probability' => 0.41,
                'form_score' => null,
                'h2h_score' => null,
                'live_score' => null,
                'components' => [
                    'pdi' => 0.45,
                    'shot_quality' => 0.50,
                    'red_flag' => 'low_accuracy',
                ],
                'debug_trace' => [
                    'gating_passed' => true,
                    'gating_reason' => '',
                    'decision_reason' => 'red_flag_low_accuracy',
                    'probability' => 0.41,
                    'red_flag' => 'low_accuracy',
                ],
            ],
            ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true],
            ['bet' => false, 'reason' => 'red_flag_low_accuracy']
        );

        $this->assertSame(2, $result['algorithm_data']['algorithm_version']);
        $this->assertTrue($result['algorithm_data']['gating_passed']);
        $this->assertSame('', $result['algorithm_data']['gating_reason']);
        $this->assertSame('red_flag_low_accuracy', $result['algorithm_data']['decision_reason']);
        $this->assertSame(0.41, $result['algorithm_data']['probability']);
        $this->assertSame('low_accuracy', $result['algorithm_data']['red_flag']);
        $this->assertSame('low_accuracy', $result['algorithm_data']['components']['red_flag']);
        $this->assertArrayHasKey('debug_trace', $result['algorithm_data']);
    }

    public function testFormatAlgorithmOneKeepsV2ScoresForMessageRendering(): void
    {
        $formatter = new ResultFormatter();

        $result = $formatter->formatAlgorithmOne(
            [
                'match_id' => 102,
                'country' => 'Testland',
                'liga' => 'Debug League',
                'home' => 'Home',
                'away' => 'Away',
            ],
            [
                'minute' => 24,
                'time_str' => '24:00',
                'match_status' => '1H',
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'shots_total' => 11,
                'shots_on_target' => 5,
                'dangerous_attacks' => 30,
                'corners' => 3,
            ],
            [
                'algorithm_version' => 2,
                'probability' => 0.56,
                'form_score' => 0.74,
                'h2h_score' => 0.60,
                'live_score' => 0.67,
                'components' => [
                    'pdi' => 0.62,
                    'shot_quality' => 0.71,
                ],
                'debug_trace' => [
                    'gating_passed' => true,
                    'gating_reason' => '',
                    'decision_reason' => 'probability_threshold_met',
                    'probability' => 0.56,
                ],
            ],
            ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true],
            ['home_goals' => 3, 'away_goals' => 3, 'has_data' => true],
            ['bet' => true, 'reason' => 'probability_threshold_met']
        );

        $this->assertSame(0.74, $result['form_score']);
        $this->assertSame(0.60, $result['h2h_score']);
        $this->assertSame(0.67, $result['live_score']);
    }
}
