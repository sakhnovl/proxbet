<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Filters;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter;

final class LegacyFilterTest extends TestCase
{
    private LegacyFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new LegacyFilter();
    }

    public function testRejectWhenNoFormData(): void
    {
        $liveData = $this->buildValidLiveData();
        $formData = ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: недостаточно данных по форме', $result['reason']);
    }

    public function testRejectWhenNoH2hData(): void
    {
        $liveData = $this->buildValidLiveData();
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: недостаточно данных по H2H', $result['reason']);
    }

    public function testRejectWhenGoalAlreadyScored(): void
    {
        $liveData = $this->buildValidLiveData(['ht_hscore' => 1, 'ht_ascore' => 0]);
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: гол уже забит в первом тайме', $result['reason']);
    }

    public function testRejectWhenAwayGoalScored(): void
    {
        $liveData = $this->buildValidLiveData(['ht_hscore' => 0, 'ht_ascore' => 1]);
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: гол уже забит в первом тайме', $result['reason']);
    }

    public function testRejectWhenTooEarly(): void
    {
        $liveData = $this->buildValidLiveData(['minute' => 14]);
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: слишком рано (минута 14)', $result['reason']);
    }

    public function testRejectWhenTooLate(): void
    {
        $liveData = $this->buildValidLiveData(['minute' => 31]);
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: слишком поздно (минута 31)', $result['reason']);
    }

    public function testRejectWhenNoShotsOnTarget(): void
    {
        $liveData = $this->buildValidLiveData(['shots_on_target' => 0]);
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: нет ударов в створ', $result['reason']);
    }

    public function testRejectWhenInsufficientDangerousAttacks(): void
    {
        $liveData = $this->buildValidLiveData(['dangerous_attacks' => 19]);
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: мало опасных атак (19)', $result['reason']);
    }

    public function testRejectWhenProbabilityBelowThreshold(): void
    {
        $liveData = $this->buildValidLiveData();
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.54, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: вероятность ниже порога (54%)', $result['reason']);
    }

    public function testAcceptWhenAllConditionsMet(): void
    {
        $liveData = $this->buildValidLiveData();
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertTrue($result['bet']);
        $this->assertSame('Алгоритм 1: высокая вероятность (60%), активная игра', $result['reason']);
    }

    public function testAcceptAtMinimumMinute(): void
    {
        $liveData = $this->buildValidLiveData(['minute' => 15]);
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertTrue($result['bet']);
    }

    public function testAcceptAtMaximumMinute(): void
    {
        $liveData = $this->buildValidLiveData(['minute' => 30]);
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertTrue($result['bet']);
    }

    public function testAcceptAtExactlyMinimumDangerousAttacks(): void
    {
        $liveData = $this->buildValidLiveData(['dangerous_attacks' => 20]);
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.60, $formData, $h2hData);

        $this->assertTrue($result['bet']);
    }

    public function testAcceptAtExactlyMinimumProbability(): void
    {
        $liveData = $this->buildValidLiveData();
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.55, $formData, $h2hData);

        $this->assertTrue($result['bet']);
        $this->assertSame('Алгоритм 1: высокая вероятность (55%), активная игра', $result['reason']);
    }

    public function testCustomMinimumProbability(): void
    {
        $filter = new LegacyFilter(minProbability: 0.60);
        $liveData = $this->buildValidLiveData();
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        // Should reject at 0.55 with custom threshold
        $result = $filter->shouldBet($liveData, 0.55, $formData, $h2hData);
        $this->assertFalse($result['bet']);

        // Should accept at 0.60
        $result = $filter->shouldBet($liveData, 0.60, $formData, $h2hData);
        $this->assertTrue($result['bet']);
    }

    public function testReasonFormattingWithHighProbability(): void
    {
        $liveData = $this->buildValidLiveData();
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true];

        $result = $this->filter->shouldBet($liveData, 0.87, $formData, $h2hData);

        $this->assertTrue($result['bet']);
        $this->assertSame('Алгоритм 1: высокая вероятность (87%), активная игра', $result['reason']);
    }

    public function testCheckOrderFormDataFirst(): void
    {
        // Form data is checked first, so even with other invalid conditions,
        // the form data error should be returned
        $liveData = $this->buildValidLiveData(['minute' => 10, 'shots_on_target' => 0]);
        $formData = ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false];
        $h2hData = ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false];

        $result = $this->filter->shouldBet($liveData, 0.30, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: недостаточно данных по форме', $result['reason']);
    }

    public function testCheckOrderH2hDataSecond(): void
    {
        // H2H is checked second, after form data
        $liveData = $this->buildValidLiveData(['minute' => 10, 'shots_on_target' => 0]);
        $formData = ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true];
        $h2hData = ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false];

        $result = $this->filter->shouldBet($liveData, 0.30, $formData, $h2hData);

        $this->assertFalse($result['bet']);
        $this->assertSame('Алгоритм 1: недостаточно данных по H2H', $result['reason']);
    }

    /**
     * Build valid live data for testing.
     *
     * @param array<string,mixed> $overrides
     * @return array{
     *   minute:int,
     *   shots_total:int,
     *   shots_on_target:int,
     *   dangerous_attacks:int,
     *   corners:int,
     *   ht_hscore:int,
     *   ht_ascore:int,
     *   live_hscore:int,
     *   live_ascore:int,
     *   time_str:string,
     *   match_status:string
     * }
     */
    private function buildValidLiveData(array $overrides = []): array
    {
        return array_merge([
            'minute' => 22,
            'shots_total' => 12,
            'shots_on_target' => 6,
            'dangerous_attacks' => 30,
            'corners' => 5,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'time_str' => '22:00',
            'match_status' => '1st Half',
        ], $overrides);
    }
}
