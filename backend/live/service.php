<?php

declare(strict_types=1);

namespace Proxbet\Live;

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/json.php';
require_once __DIR__ . '/extract.php';
require_once __DIR__ . '/match.php';
require_once __DIR__ . '/update.php';
require_once __DIR__ . '/../line/logger.php';

use PDO;
use Proxbet\Line\Logger;

final class LiveService
{
    public static function run(PDO $pdo): void
    {
        $payload = Client::fetchLiveJson();

        $node = Extract::findNodeWithValue($payload);
        if ($node === null) {
            Logger::info('Live parser: no Value node found');
            return;
        }

        $value = $node['Value'];
        if (!is_array($value)) {
            Logger::info('Live parser: Value is not an array');
            return;
        }

        $rows = self::loadCandidateMatches($pdo);
        if ($rows === []) {
            Logger::info('Live parser: no candidate matches from DB');
            return;
        }

        $matchedAny = false;
        $updatedStatsFieldsTotal = 0;

        // Value contains list of live matches (often numeric keys).
        foreach (Json::children($value) as $valueNode) {
            $meta = Extract::extractTeamsAndId($valueNode);
            $o1 = $meta['O1'];
            $o2 = $meta['O2'];
            if ($o1 === null || $o2 === null) {
                continue;
            }

            foreach ($rows as $r) {
                $evid = (string) ($r['evid'] ?? '');
                if ($evid === '') {
                    continue;
                }

                if (!MatchUtil::isSameTeams($r['home'] ?? null, $r['away'] ?? null, $o1, $o2)) {
                    continue;
                }

                $matchedAny = true;
                Logger::info('Live match found', ['evid' => $evid, 'home' => $o1, 'away' => $o2]);

                // live_evid only if NULL
                Update::updateLiveEvidIfNull($pdo, $evid, $meta['I']);

                // scores always
                $scores = Extract::extractScore($valueNode);
                Update::updateScores($pdo, $evid, $scores);

                // time/status always
                $timeAndStatus = Extract::extractTimeAndStatus($valueNode);
                Update::updateTimeAndStatus($pdo, $evid, $timeAndStatus);

                // stats always
                $stats = Extract::extractStats($valueNode);
                $updatedStatsFieldsTotal += Update::updateStats($pdo, $evid, $stats);

                // A DB match should correspond to one live node; break inner loop.
                break;
            }
        }

        if (!$matchedAny) {
            Logger::info('Live parser: no matches matched by O1/O2');
            return;
        }

        Logger::info('Live parser finished', ['updated_stat_fields' => $updatedStatsFieldsTotal]);
    }

    /**
     * Loads DB matches to be used for O1/O2 matching.
     * For now we take all matches that have home/away.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function loadCandidateMatches(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT `evid`,`home`,`away` FROM `matches` WHERE `home` IS NOT NULL AND `away` IS NOT NULL');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}
