<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';

use Proxbet\Line\Logger;
use Proxbet\Statistic\StatCli;

proxbet_bootstrap_env();
Logger::init();

$cli = new StatCli();
exit($cli->run($argv));
