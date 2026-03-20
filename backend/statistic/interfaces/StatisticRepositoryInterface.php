<?php

declare(strict_types=1);

namespace Proxbet\Statistic\Interfaces;

interface StatisticRepositoryInterface
{
    /**
     * @return array<int,array{match_id:int, sgi:string, home:string, away:string, sgi_json:?string}>
     */
    public function listMatchesToUpdate(int $limit, int $offset, bool $force, int $staleAfterSeconds, string $statsVersion, ?int $matchId = null): array;

    public function saveSgiJson(int $matchId, string $rawJson): void;

    /**
     * @param array<string,int|float|null> $metrics
     */
    public function saveMetrics(int $matchId, array $metrics): void;

    /**
     * @param array<string,mixed> $data
     */
    public function saveStatMeta(int $matchId, array $data): void;
}
