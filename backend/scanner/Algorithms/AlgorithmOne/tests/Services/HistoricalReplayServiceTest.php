<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Services;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\HistoricalReplayService;

final class HistoricalReplayServiceTest extends TestCase
{
    public function testResolveMatchOutcomeReturnsWonWhenFirstHalfGoalExists(): void
    {
        $service = new HistoricalReplayService();

        $result = $service->resolveMatchOutcome([
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 0,
            'match_status' => 'Перерыв',
            'time' => '45:00',
        ]);

        $this->assertSame('won', $result);
    }

    public function testResolveMatchOutcomeReturnsLostWhenHalftimeFinishedWithoutGoals(): void
    {
        $service = new HistoricalReplayService();

        $result = $service->resolveMatchOutcome([
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'match_status' => 'Перерыв',
            'time' => '45:00',
        ]);

        $this->assertSame('lost', $result);
    }

    public function testNormalizeSnapshotsKeepsOnlyReplayWindow(): void
    {
        $service = new HistoricalReplayService();

        $snapshots = $service->normalizeSnapshots([
            ['minute' => 12, 'time' => '12:00', 'captured_at' => '2026-03-21 10:00:00'],
            ['minute' => 17, 'time' => '17:00', 'captured_at' => '2026-03-21 10:05:00'],
            ['minute' => 31, 'time' => '31:00', 'captured_at' => '2026-03-21 10:10:00'],
            ['minute' => 25, 'time' => '25:00', 'captured_at' => '2026-03-21 10:08:00'],
        ]);

        $this->assertCount(2, $snapshots);
        $this->assertSame(17, $snapshots[0]['minute']);
        $this->assertSame(25, $snapshots[1]['minute']);
    }

    public function testReplayBuildsAllProfileSummaries(): void
    {
        $service = new HistoricalReplayService();

        $report = $service->replay([
            [
                'match' => $this->buildFinishedMatch(),
                'snapshots' => [
                    $this->buildSnapshot(18, 0, 0, 6, 26, 0.7, 0.4),
                    $this->buildSnapshot(23, 0, 0, 8, 34, 1.0, 0.7),
                ],
            ],
        ]);

        $this->assertSame(1, $report['summary']['matches_replayed']);
        $this->assertArrayHasKey('legacy', $report['profiles']);
        $this->assertArrayHasKey('current_v2', $report['profiles']);
        $this->assertArrayHasKey('fixed_v2', $report['profiles']);
        $this->assertArrayHasKey('tuned_v2', $report['profiles']);
        $this->assertSame(1, $report['profiles']['legacy']['matches']);
        $this->assertSame(1, count($report['match_results']));
    }

    /**
     * @return array<string,mixed>
     */
    private function buildFinishedMatch(): array
    {
        return [
            'id' => 1001,
            'country' => 'England',
            'liga' => 'Premier League',
            'home' => 'Alpha FC',
            'away' => 'Beta FC',
            'ht_match_goals_1' => 4,
            'ht_match_goals_2' => 3,
            'h2h_ht_match_goals_1' => 2,
            'h2h_ht_match_goals_2' => 2,
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 0,
            'match_status' => 'Перерыв',
            'time' => '45:00',
            'table_avg' => 2.8,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSnapshot(
        int $minute,
        int $htHome,
        int $htAway,
        int $shotsOnTarget,
        int $dangerousAttacks,
        float $xgHome,
        float $xgAway,
    ): array {
        return [
            'minute' => $minute,
            'time' => sprintf('%02d:00', $minute),
            'match_status' => '1-й тайм',
            'live_ht_hscore' => $htHome,
            'live_ht_ascore' => $htAway,
            'live_hscore' => $htHome,
            'live_ascore' => $htAway,
            'live_xg_home' => $xgHome,
            'live_xg_away' => $xgAway,
            'live_danger_att_home' => (int) floor($dangerousAttacks / 2),
            'live_danger_att_away' => $dangerousAttacks - (int) floor($dangerousAttacks / 2),
            'live_shots_on_target_home' => (int) floor($shotsOnTarget / 2),
            'live_shots_on_target_away' => $shotsOnTarget - (int) floor($shotsOnTarget / 2),
            'live_shots_off_target_home' => 2,
            'live_shots_off_target_away' => 2,
            'live_yellow_cards_home' => 1,
            'live_yellow_cards_away' => 1,
            'live_corner_home' => 2,
            'live_corner_away' => 2,
            'captured_at' => sprintf('2026-03-21 10:%02d:00', $minute),
        ];
    }
}
