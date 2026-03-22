<?php

declare(strict_types=1);

namespace Proxbet\Core\Commands;

use Proxbet\Core\Services\BetCheckerService;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;
use Proxbet\Scanner\BetMessageRepository;

final class BetCheckerCommand
{
    public function run(): int
    {
        \proxbet_bootstrap_env();
        Logger::init();
        Logger::info('Bet checker CLI started');

        try {
            \proxbet_require_env(['TELEGRAM_BOT_TOKEN']);
            $botToken = (string) getenv('TELEGRAM_BOT_TOKEN');

            $db = Db::connectFromEnv();
            $repository = new BetMessageRepository($db);
            $service = new BetCheckerService($repository, $botToken);
            $result = $service->checkPendingBets();

            if ($result['checked'] > 0) {
                $service->displayStatistics();
            }

            return $result['errors'] > 0 ? 1 : 0;
        } catch (\Throwable $e) {
            Logger::error('Bet checker failed', ['error' => $e->getMessage()]);
            return 1;
        }
    }
}
