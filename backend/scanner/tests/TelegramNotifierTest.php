<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\TelegramNotifier;

final class TelegramNotifierTest extends TestCase
{
    public function testFormatMessageInjectsAiVerdictIntoAlgorithmOneV2ChannelMessage(): void
    {
        $notifier = new TelegramNotifier('token', '@channel', sys_get_temp_dir() . '/scanner-state-test.json');
        $method = new \ReflectionMethod($notifier, 'formatMessage');
        $method->setAccessible(true);

        $message = $method->invoke($notifier, [
            'algorithm_id' => 1,
            'algorithm_name' => 'Алгоритм 1',
            'home' => 'Хьюстон Динамо II',
            'away' => 'Норт Техас',
            'liga' => 'Чемпионат США. MLS Next Pro',
            'time' => '27:22',
            'score_home' => 0,
            'score_away' => 0,
            'probability' => 0.67,
            'form_score' => 0.98,
            'h2h_score' => 0.50,
            'live_score' => 0.34,
            'stats' => [
                'shots_total' => 5,
                'shots_on_target' => 3,
                'dangerous_attacks' => 50,
                'corners' => 1,
            ],
            'form_data' => [
                'home_goals' => 3,
                'away_goals' => 2,
            ],
            'h2h_data' => [
                'home_goals' => 3,
                'away_goals' => 2,
            ],
            'algorithm_data' => [
                'algorithm_version' => 2,
                'components' => [
                    'pdi' => 1.00,
                    'shot_quality' => 0.31,
                    'trend_acceleration' => 0.17,
                    'xg_pressure' => 0.21,
                    'time_pressure' => 0.77,
                    'league_factor' => 1.30,
                    'card_factor' => 0.00,
                ],
            ],
        ], 'Темп хороший, гол назревает');

        $this->assertStringContainsString('<b>AI:</b> Темп хороший, гол назревает', $message);
        $this->assertStringContainsString('Алгоритм 1 v2', $message);
        $this->assertStringContainsString('Вероятность', $message);
    }

    public function testFormatMessageSkipsAiBlockWhenVerdictIsEmpty(): void
    {
        $notifier = new TelegramNotifier('token', '@channel', sys_get_temp_dir() . '/scanner-state-test.json');
        $method = new \ReflectionMethod($notifier, 'formatMessage');
        $method->setAccessible(true);

        $message = $method->invoke($notifier, [
            'algorithm_id' => 1,
            'algorithm_name' => 'Алгоритм 1',
            'home' => 'Alpha',
            'away' => 'Beta',
            'liga' => 'League',
            'time' => '12:00',
            'score_home' => 0,
            'score_away' => 0,
            'probability' => 0.67,
            'form_score' => 0.98,
            'h2h_score' => 0.50,
            'live_score' => 0.34,
            'stats' => [],
            'form_data' => [],
            'h2h_data' => [],
            'algorithm_data' => null,
        ], null);

        $this->assertStringNotContainsString('<b>AI:</b>', $message);
    }
}
