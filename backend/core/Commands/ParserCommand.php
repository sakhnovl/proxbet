<?php

declare(strict_types=1);

namespace Proxbet\Core\Commands;

use Proxbet\Core\Services\ParserService;
use Proxbet\Line\Db;
use Proxbet\Line\Logger;

final class ParserCommand
{
    public function run(): int
    {
        \proxbet_bootstrap_env();
        Logger::init();

        try {
            \proxbet_require_env(['API_URL']);
            $apiUrl = (string) getenv('API_URL');

            $db = Db::connectFromEnv();
            $service = new ParserService($db);

            $matches = $service->fetchMatches($apiUrl);
            $result = $service->filterByBans($matches);
            $stats = $service->saveMatches($result['filtered']);
            $stats['banned'] = $result['banned'];

            Logger::info('DB upsert finished', $stats);
            return 0;
        } catch (\Throwable $e) {
            Logger::error('Parser failed', ['error' => $e->getMessage()]);
            return 1;
        }
    }
}
