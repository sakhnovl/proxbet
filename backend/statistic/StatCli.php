<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

use Proxbet\Line\Logger;

/**
 * CLI handler for statistics update command.
 */
final class StatCli
{
    /**
     * Run the statistics update process.
     *
     * @param array<int,string> $argv
     */
    public function run(array $argv): int
    {
        $options = $this->parseOptions();

        // Handle local JSON test mode
        if ($options['local_json'] !== null) {
            return $this->runLocalTest($options);
        }

        // Run normal statistics update
        return $this->runUpdate($options);
    }

    /**
     * Parse command line options.
     *
     * @return array{limit:int, offset:int, force:bool, local_json:?string, home:?string, away:?string, match_id:?int}
     */
    private function parseOptions(): array
    {
        $opts = getopt('', ['limit::', 'offset::', 'force', 'local_json::', 'home::', 'away::', 'match_id::']);

        $limit = isset($opts['limit']) ? (int) $opts['limit'] : (int) (getenv('STAT_BATCH_LIMIT') ?: 100);
        $offset = isset($opts['offset']) ? (int) $opts['offset'] : 0;
        $force = array_key_exists('force', $opts);

        $localJson = isset($opts['local_json']) && is_string($opts['local_json']) && trim($opts['local_json']) !== ''
            ? trim($opts['local_json'])
            : null;

        $home = isset($opts['home']) && is_string($opts['home']) && trim($opts['home']) !== ''
            ? trim($opts['home'])
            : null;

        $away = isset($opts['away']) && is_string($opts['away']) && trim($opts['away']) !== ''
            ? trim($opts['away'])
            : null;
        $matchId = isset($opts['match_id']) ? (int) $opts['match_id'] : null;
        if ($matchId !== null && $matchId <= 0) {
            $matchId = null;
        }

        return [
            'limit' => max(1, min(1000, $limit)),
            'offset' => max(0, $offset),
            'force' => $force,
            'local_json' => $localJson,
            'home' => $home,
            'away' => $away,
            'match_id' => $matchId,
        ];
    }

    /**
     * Run local JSON test mode.
     *
     * @param array{local_json:string, home:?string, away:?string} $options
     */
    private function runLocalTest(array $options): int
    {
        $path = $options['local_json'];
        $raw = @file_get_contents($path);
        
        if ($raw === false || trim($raw) === '') {
            Logger::error('Failed to read local_json', ['path' => $path]);
            return 1;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Logger::error('Invalid local_json', ['path' => $path, 'json_error' => json_last_error_msg()]);
            return 1;
        }

        $calculator = new HtMetricsCalculator();
        
        $home = $options['home'] ?? $this->extractTeamName($decoded, 'H');
        $away = $options['away'] ?? $this->extractTeamName($decoded, 'A');

        if ($home === '' || $away === '') {
            Logger::error('Missing home/away for local_json (pass --home/--away or ensure H.T/A.T exist)', ['path' => $path]);
            return 1;
        }

        $details = $calculator->calculateAll($decoded, $home, $away);
        echo json_encode(['home' => $home, 'away' => $away] + $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        
        return 0;
    }

    /**
     * Run normal statistics update.
     *
     * @param array{limit:int, offset:int, force:bool, match_id:?int} $options
     */
    private function runUpdate(array $options): int
    {
        try {
            $service = StatisticServiceFactory::create();
            $result = $service->updateStatistics($options['limit'], $options['offset'], $options['force'], $options['match_id']);

            Logger::info('Statistic update finished', $result);
            return 0;
        } catch (\Throwable $e) {
            Logger::error('Statistic update failed', ['error' => $e->getMessage()]);
            return 1;
        }
    }

    /**
     * Extract team name from SGI data.
     */
    private function extractTeamName(array $sgi, string $side): string
    {
        $obj = $sgi[$side] ?? null;
        if (!is_array($obj)) {
            return '';
        }

        $t = $obj['T'] ?? null;
        if (is_string($t)) {
            return trim($t);
        }

        if (is_array($t) && isset($t['T']) && is_string($t['T'])) {
            return trim($t['T']);
        }

        return '';
    }
}
