<?php

declare(strict_types=1);

namespace Proxbet\Core\Commands;

use Proxbet\Core\Services\LiveUpdateService;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;

final class LiveUpdateCommand
{
    public function run(): int
    {
        \proxbet_bootstrap_env();
        Logger::init();

        try {
            $db = Db::connectFromEnv();
            $service = new LiveUpdateService($db);
            $service->runLiveUpdates();
            $service->forceFinishStaleMatches();

            return 0;
        } catch (\Throwable $e) {
            Logger::error('Live parser failed', ['error' => $e->getMessage()]);
            return 1;
        }
    }
}
