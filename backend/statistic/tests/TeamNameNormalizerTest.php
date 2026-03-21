<?php

declare(strict_types=1);

namespace Proxbet\Statistic\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Statistic\TeamNameNormalizer;

final class TeamNameNormalizerTest extends TestCase
{
    public function testNormalizeBasic(): void
    {
        $this->assertSame('manchester united', TeamNameNormalizer::normalize('Manchester United'));
        $this->assertSame('barcelona', TeamNameNormalizer::normalize('Barcelona'));
        $this->assertSame('real madrid', TeamNameNormalizer::normalize('Real Madrid'));
    }

    public function testNormalizeWithSpecialCharacters(): void
    {
        $this->assertSame('manchester united', TeamNameNormalizer::normalize('Manchester  United'));
        $this->assertSame('real madrid', TeamNameNormalizer::normalize('Real-Madrid'));
        $this->assertSame('paris saint germain', TeamNameNormalizer::normalize('P.S.G.'));
    }

    public function testNormalizeAliases(): void
    {
        $this->assertSame('manchester united', TeamNameNormalizer::normalize('Man Utd'));
        $this->assertSame('manchester united', TeamNameNormalizer::normalize('Man United'));
        $this->assertSame('manchester city', TeamNameNormalizer::normalize('Man City'));
        $this->assertSame('paris saint germain', TeamNameNormalizer::normalize('PSG'));
        $this->assertSame('inter', TeamNameNormalizer::normalize('Internazionale'));
    }

    public function testNormalizeEmptyString(): void
    {
        $this->assertSame('', TeamNameNormalizer::normalize(''));
        $this->assertSame('', TeamNameNormalizer::normalize('   '));
    }

    public function testEquals(): void
    {
        $this->assertTrue(TeamNameNormalizer::equals('Manchester United', 'Man Utd'));
        $this->assertTrue(TeamNameNormalizer::equals('PSG', 'Paris Saint Germain'));
        $this->assertFalse(TeamNameNormalizer::equals('Manchester United', 'Manchester City'));
        $this->assertFalse(TeamNameNormalizer::equals('', 'Manchester United'));
    }

    public function testNormalizeCyrillic(): void
    {
        $this->assertSame('спартак', TeamNameNormalizer::normalize('Спартак'));
        $this->assertSame('цска', TeamNameNormalizer::normalize('ЦСКА'));
    }
}
