<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Services;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\LeagueProfileService;

final class LeagueProfileServiceTest extends TestCase
{
    private LeagueProfileService $service;

    protected function setUp(): void
    {
        $this->service = new LeagueProfileService();
    }

    public function testClassifiesWomenLeague(): void
    {
        $context = $this->service->buildContext('England', 'Women Super League');

        $this->assertSame(Config::LEAGUE_CATEGORY_WOMEN, $context['category']);
        $this->assertSame(0.50, $context['profile']['probability_threshold']);
    }

    public function testClassifiesYouthLeague(): void
    {
        $context = $this->service->buildContext('Italy', 'Primavera U20');

        $this->assertSame(Config::LEAGUE_CATEGORY_YOUTH, $context['category']);
        $this->assertSame(1.20, $context['profile']['min_attack_tempo']);
    }

    public function testClassifiesKnownTopTierLeague(): void
    {
        $context = $this->service->buildContext('England', 'Premier League');

        $this->assertSame(Config::LEAGUE_CATEGORY_TOP_TIER, $context['category']);
        $this->assertSame(0.55, $context['profile']['probability_threshold']);
    }

    public function testFallsBackToLowTierLeague(): void
    {
        $context = $this->service->buildContext('Brazil', 'Serie C');

        $this->assertSame(Config::LEAGUE_CATEGORY_LOW_TIER, $context['category']);
        $this->assertSame(0.98, $context['profile']['missing_h2h_penalty']);
    }
}
