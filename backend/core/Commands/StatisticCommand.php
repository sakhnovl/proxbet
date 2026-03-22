<?php

declare(strict_types=1);

namespace Proxbet\Core\Commands;

use Proxbet\Line\Logger;
use Proxbet\Statistic\StatCli;

final class StatisticCommand
{
    /**
     * @param array<int,string> $argv
     */
    public function run(array $argv): int
    {
        \proxbet_bootstrap_env();
        Logger::init();

        return (new StatCli())->run($argv);
    }
}
