<?php

declare(strict_types=1);

namespace Proxbet\Core\Commands;

use Proxbet\Core\Services\CleanupService;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;

final class CleanupCommand
{
    public function run(): int
    {
        \proxbet_bootstrap_env();
        Logger::init();

        try {
            $db = Db::connectFromEnv();
            $result = (new CleanupService($db))->cleanupFinishedMatches();

            if ($result['deleted_matches'] > 0) {
                Logger::info('Cleanup finished matches', [
                    'deleted_matches' => $result['deleted_matches'],
                    'deleted_snapshots' => $result['deleted_snapshots'],
                ]);

                foreach ($result['details'] as $detail) {
                    Logger::info('Deleted match', $detail);
                }
            }

            return 0;
        } catch (\Throwable $e) {
            Logger::error('Cleanup failed', ['error' => $e->getMessage()]);
            return 1;
        }
    }
}
