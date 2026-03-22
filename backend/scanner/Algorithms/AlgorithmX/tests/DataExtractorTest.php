<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataExtractor;

final class DataExtractorTest extends TestCase
{
    private DataExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new DataExtractor();
    }

    public function testExtractBuildsStructuredLivePayload(): void
    {
        $result = $this->extractor->extract([
            'time' => '18:45',
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 15,
            'live_danger_att_away' => 11,
            'live_shots_on_target_home' => 4,
            'live_shots_on_target_away' => 3,
            'live_shots_off_target_home' => 5,
            'live_shots_off_target_away' => 4,
            'live_corner_home' => 3,
            'live_corner_away' => 2,
            'match_status' => 'In Play',
        ]);

        $this->assertSame(18, $result['minute']);
        $this->assertSame(1, $result['score_home']);
        $this->assertSame(0, $result['score_away']);
        $this->assertSame(9, $result['shots_home']);
        $this->assertSame(7, $result['shots_away']);
        $this->assertTrue($result['has_data']);
    }

    public function testExtractMarksPayloadWithoutStatsAsMissingData(): void
    {
        $result = $this->extractor->extract([
            'time' => '20:00',
            'match_status' => 'In Play',
        ]);

        $this->assertSame(20, $result['minute']);
        $this->assertFalse($result['has_data']);
        $this->assertSame(0, $result['shots_home']);
        $this->assertSame(0, $result['shots_away']);
    }

    public function testExtractKeepsZeroValuedStatsAsValidData(): void
    {
        $result = $this->extractor->extract([
            'time' => '12:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 0,
            'live_danger_att_away' => 0,
            'live_shots_on_target_home' => 0,
            'live_shots_on_target_away' => 0,
            'live_shots_off_target_home' => 0,
            'live_shots_off_target_away' => 0,
            'live_corner_home' => 0,
            'live_corner_away' => 0,
            'match_status' => 'In Play',
        ]);

        $this->assertTrue($result['has_data']);
        $this->assertSame(0, $result['shots_home']);
        $this->assertSame(0, $result['shots_away']);
    }
}
