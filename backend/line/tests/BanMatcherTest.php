<?php

declare(strict_types=1);

namespace Proxbet\Line\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Line\BanMatcher;

/**
 * Unit tests for BanMatcher - ban matching logic.
 */
final class BanMatcherTest extends TestCase
{
    public function testNormalizeBasic(): void
    {
        $this->assertEquals('arsenal', BanMatcher::normalize('Arsenal'));
        $this->assertEquals('arsenal', BanMatcher::normalize('ARSENAL'));
        $this->assertEquals('arsenal', BanMatcher::normalize('  Arsenal  '));
    }

    public function testNormalizeRemovesStopwords(): void
    {
        $this->assertEquals('arsenal', BanMatcher::normalize('Arsenal FC'));
        $this->assertEquals('barcelona', BanMatcher::normalize('FC Barcelona'));
        $this->assertEquals('manchester united', BanMatcher::normalize('Manchester United FC'));
    }

    public function testNormalizeHandlesSpecialCharacters(): void
    {
        $this->assertEquals('ohiggins', BanMatcher::normalize("O'Higgins"));
        $this->assertEquals('sao paulo', BanMatcher::normalize('São Paulo'));
        $this->assertEquals('real madrid', BanMatcher::normalize('Real Madrid'));
    }

    public function testNormalizeHandlesCyrillic(): void
    {
        $this->assertEquals('спартак москва', BanMatcher::normalize('Спартак Москва'));
        $this->assertEquals('зенит', BanMatcher::normalize('Зенит'));
        $this->assertEquals('цска', BanMatcher::normalize('ЦСКА'));
    }

    public function testNormalizeHandlesYo(): void
    {
        $this->assertEquals('елка', BanMatcher::normalize('ёлка'));
        $this->assertEquals('елка', BanMatcher::normalize('елка'));
    }

    public function testFieldMatchesExactMatch(): void
    {
        $this->assertTrue(BanMatcher::fieldMatches('Arsenal', 'Arsenal'));
        $this->assertTrue(BanMatcher::fieldMatches('arsenal fc', 'Arsenal FC'));
    }

    public function testFieldMatchesSubstring(): void
    {
        $this->assertTrue(BanMatcher::fieldMatches('Arsenal', 'Arsenal London'));
        $this->assertTrue(BanMatcher::fieldMatches('Arsenal London', 'Arsenal'));
        $this->assertTrue(BanMatcher::fieldMatches('Real', 'Real Madrid'));
    }

    public function testFieldMatchesNoMatch(): void
    {
        $this->assertFalse(BanMatcher::fieldMatches('Arsenal', 'Chelsea'));
        $this->assertFalse(BanMatcher::fieldMatches('Barcelona', 'Real Madrid'));
    }

    public function testFieldMatchesCaseInsensitive(): void
    {
        $this->assertTrue(BanMatcher::fieldMatches('arsenal', 'ARSENAL'));
        $this->assertTrue(BanMatcher::fieldMatches('BARCELONA', 'barcelona'));
    }

    public function testMatchBanAllFieldsMatch(): void
    {
        $ban = [
            'id' => 1,
            'country' => 'England',
            'liga' => 'Premier League',
            'home' => 'Arsenal',
            'away' => 'Chelsea',
            'is_active' => 1,
        ];

        $match = [
            'country' => 'England',
            'liga' => 'Premier League',
            'home' => 'Arsenal FC',
            'away' => 'Chelsea FC',
        ];

        $result = BanMatcher::matchBan($ban, $match);

        $this->assertTrue($result['matched']);
        $this->assertEquals(1, $result['ban_id']);
        $this->assertCount(4, $result['fields']);
    }

    public function testMatchBanPartialFieldsMatch(): void
    {
        $ban = [
            'id' => 2,
            'country' => 'Spain',
            'liga' => null,
            'home' => 'Barcelona',
            'away' => null,
            'is_active' => 1,
        ];

        $match = [
            'country' => 'Spain',
            'liga' => 'La Liga',
            'home' => 'FC Barcelona',
            'away' => 'Real Madrid',
        ];

        $result = BanMatcher::matchBan($ban, $match);

        $this->assertTrue($result['matched']);
        $this->assertContains('country', $result['fields']);
        $this->assertContains('home', $result['fields']);
    }

    public function testMatchBanNoMatch(): void
    {
        $ban = [
            'id' => 3,
            'country' => 'Germany',
            'liga' => 'Bundesliga',
            'home' => 'Bayern',
            'away' => null,
            'is_active' => 1,
        ];

        $match = [
            'country' => 'Germany',
            'liga' => 'Bundesliga',
            'home' => 'Dortmund',
            'away' => 'Leipzig',
        ];

        $result = BanMatcher::matchBan($ban, $match);

        $this->assertFalse($result['matched']);
    }

    public function testMatchBanInactive(): void
    {
        $ban = [
            'id' => 4,
            'country' => 'Italy',
            'liga' => 'Serie A',
            'home' => 'Juventus',
            'away' => null,
            'is_active' => 0,
        ];

        $match = [
            'country' => 'Italy',
            'liga' => 'Serie A',
            'home' => 'Juventus',
            'away' => 'Inter',
        ];

        $result = BanMatcher::matchBan($ban, $match);

        $this->assertFalse($result['matched']);
    }

    public function testMatchBanEmptyRule(): void
    {
        $ban = [
            'id' => 5,
            'country' => null,
            'liga' => null,
            'home' => null,
            'away' => null,
            'is_active' => 1,
        ];

        $match = [
            'country' => 'France',
            'liga' => 'Ligue 1',
            'home' => 'PSG',
            'away' => 'Lyon',
        ];

        $result = BanMatcher::matchBan($ban, $match);

        $this->assertFalse($result['matched']);
    }

    public function testMatchAnyFindsMatch(): void
    {
        $bans = [
            [
                'id' => 1,
                'country' => 'England',
                'liga' => null,
                'home' => 'Arsenal',
                'away' => null,
                'is_active' => 1,
            ],
            [
                'id' => 2,
                'country' => 'Spain',
                'liga' => null,
                'home' => 'Barcelona',
                'away' => null,
                'is_active' => 1,
            ],
        ];

        $match = [
            'country' => 'Spain',
            'liga' => 'La Liga',
            'home' => 'FC Barcelona',
            'away' => 'Real Madrid',
        ];

        $result = BanMatcher::matchAny($bans, $match);

        $this->assertTrue($result['matched']);
        $this->assertEquals(2, $result['ban']['id']);
    }

    public function testMatchAnyNoMatch(): void
    {
        $bans = [
            [
                'id' => 1,
                'country' => 'England',
                'liga' => null,
                'home' => 'Arsenal',
                'away' => null,
                'is_active' => 1,
            ],
        ];

        $match = [
            'country' => 'Spain',
            'liga' => 'La Liga',
            'home' => 'Barcelona',
            'away' => 'Real Madrid',
        ];

        $result = BanMatcher::matchAny($bans, $match);

        $this->assertFalse($result['matched']);
    }

    public function testMatchBanCountryOnly(): void
    {
        $ban = [
            'id' => 6,
            'country' => 'Russia',
            'liga' => null,
            'home' => null,
            'away' => null,
            'is_active' => 1,
        ];

        $match = [
            'country' => 'Russia',
            'liga' => 'Premier League',
            'home' => 'Spartak',
            'away' => 'CSKA',
        ];

        $result = BanMatcher::matchBan($ban, $match);

        $this->assertTrue($result['matched']);
        $this->assertContains('country', $result['fields']);
    }

    public function testMatchBanLigaOnly(): void
    {
        $ban = [
            'id' => 7,
            'country' => null,
            'liga' => 'Champions League',
            'home' => null,
            'away' => null,
            'is_active' => 1,
        ];

        $match = [
            'country' => 'Europe',
            'liga' => 'UEFA Champions League',
            'home' => 'Bayern',
            'away' => 'PSG',
        ];

        $result = BanMatcher::matchBan($ban, $match);

        $this->assertTrue($result['matched']);
        $this->assertContains('liga', $result['fields']);
    }
}
