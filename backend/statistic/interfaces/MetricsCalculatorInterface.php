<?php

declare(strict_types=1);

namespace Proxbet\Statistic\Interfaces;

interface MetricsCalculatorInterface
{
    /**
     * Calculate metrics for a match.
     *
     * @param array<string,mixed> $sgi SGI JSON data
     * @param string $home Home team name
     * @param string $away Away team name
     * @return array{metrics: array<string,int|float|null>, debug: array<string,mixed>}
     */
    public function calculate(array $sgi, string $home, string $away): array;
}
