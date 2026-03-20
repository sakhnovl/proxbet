<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

use Proxbet\Line\Db;

/**
 * Factory for creating StatisticService with all dependencies.
 */
final class StatisticServiceFactory
{
    public static function create(): StatisticService
    {
        $db = Db::connectFromEnv();
        $config = new Config();
        $client = new EventsstatClient($config);
        $repo = new StatisticRepository($db);
        $calculator = new HtMetricsCalculator();

        return new StatisticService($config, $client, $repo, $calculator, $db);
    }
}
