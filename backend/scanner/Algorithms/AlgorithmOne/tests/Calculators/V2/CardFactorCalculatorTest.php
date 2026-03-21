<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators\V2;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\CardFactorCalculator;

final class CardFactorCalculatorTest extends TestCase
{
    private CardFactorCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CardFactorCalculator();
    }

    public function testReturnsZeroWhenCardsAreEqual(): void
    {
        $liveData = [
            'yellow_cards_home' => 2,
            'yellow_cards_away' => 2,
            'red_cards_home' => 0,
            'red_cards_away' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.0, $result);
    }

    public function testPositiveValueWhenAwayHasMoreCards(): void
    {
        $liveData = [
            'yellow_cards_home' => 1,
            'yellow_cards_away' => 2,
            'red_cards_home' => 0,
            'red_cards_away' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        // Diff = 1, positive
        $this->assertSame(0.03, $result);
    }

    public function testNegativeValueWhenHomeHasMoreCards(): void
    {
        $liveData = [
            'yellow_cards_home' => 3,
            'yellow_cards_away' => 1,
            'red_cards_home' => 0,
            'red_cards_away' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        // Diff = -2, negative
        $this->assertSame(-0.08, $result);
    }

    public function testDifferenceOf1(): void
    {
        $liveData = [
            'yellow_cards_home' => 0,
            'yellow_cards_away' => 1,
            'red_cards_home' => 0,
            'red_cards_away' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.03, $result);
    }

    public function testDifferenceOf2(): void
    {
        $liveData = [
            'yellow_cards_home' => 1,
            'yellow_cards_away' => 3,
            'red_cards_home' => 0,
            'red_cards_away' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.08, $result);
    }

    public function testDifferenceOf3OrMore(): void
    {
        $liveData = [
            'yellow_cards_home' => 0,
            'yellow_cards_away' => 4,
            'red_cards_home' => 0,
            'red_cards_away' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.15, $result);
    }

    public function testRedCardsCountAsTwo(): void
    {
        $liveData = [
            'yellow_cards_home' => 1,
            'yellow_cards_away' => 0,
            'red_cards_home' => 0,
            'red_cards_away' => 1,
        ];

        $result = $this->calculator->calculate($liveData);

        // Home: 1, Away: 2 (red counts as 2), diff = 1
        $this->assertSame(0.03, $result);
    }

    public function testCombinedYellowAndRedCards(): void
    {
        $liveData = [
            'yellow_cards_home' => 2,
            'yellow_cards_away' => 1,
            'red_cards_home' => 1,
            'red_cards_away' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        // Home: 2 + 2 = 4, Away: 1, diff = -3
        $this->assertSame(-0.15, $result);
    }

    public function testHandlesMissingData(): void
    {
        $liveData = [];

        $result = $this->calculator->calculate($liveData);

        // All default to 0, diff = 0
        $this->assertSame(0.0, $result);
    }

    public function testNegativeDifferenceOf1(): void
    {
        $liveData = [
            'yellow_cards_home' => 2,
            'yellow_cards_away' => 1,
            'red_cards_home' => 0,
            'red_cards_away' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(-0.03, $result);
    }
}
