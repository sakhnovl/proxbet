<?php

declare(strict_types=1);

namespace Proxbet\Statistic\Interfaces;

interface StatisticServiceInterface
{
    /**
     * Update statistics for matches.
     *
     * @return array{selected:int, updated:int, errors:int}
     */
    public function updateStatistics(int $limit, int $offset, bool $force, ?int $matchId = null): array;
}
