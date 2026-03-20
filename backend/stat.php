<?php

declare(strict_types=1);

require_once __DIR__ . '/line/env.php';
require_once __DIR__ . '/line/logger.php';
require_once __DIR__ . '/line/db.php';

require_once __DIR__ . '/statistic/Config.php';
require_once __DIR__ . '/statistic/Http.php';
require_once __DIR__ . '/statistic/EventsstatClient.php';
require_once __DIR__ . '/statistic/StatisticRepository.php';
require_once __DIR__ . '/statistic/TeamNameNormalizer.php';
require_once __DIR__ . '/statistic/HtMetricsCalculator.php';
require_once __DIR__ . '/statistic/StatisticService.php';
require_once __DIR__ . '/statistic/StatisticServiceFactory.php';
require_once __DIR__ . '/statistic/StatCli.php';

use Proxbet\Line\Env;
use Proxbet\Line\Logger;
use Proxbet\Statistic\StatCli;

Env::load(__DIR__ . '/../.env');
Logger::init();

$cli = new StatCli();
exit($cli->run($argv));
