<?php

declare(strict_types=1);

namespace Proxbet\Core\Services;

use PDO;
use Proxbet\Line\BanMatcher;
use Proxbet\Line\Db;
use Proxbet\Line\Http;
use Proxbet\Line\Logger;

require_once __DIR__ . '/../../line/extractMatches.php';

use function Proxbet\Line\extractMatches;

/**
 * Service for parsing and processing matches from API.
 */
class ParserService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Fetch matches from API URL.
     *
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException
     */
    public function fetchMatches(string $apiUrl): array
    {
        $payload = Http::getJson($apiUrl);
        $matches = extractMatches($payload);
        
        Logger::info('Extracted matches', ['count' => count($matches)]);
        
        return $matches;
    }

    /**
     * Filter matches by active bans.
     *
     * @param array<int, array<string, mixed>> $matches
     * @return array{filtered: array<int, array<string, mixed>>, banned: int}
     */
    public function filterByBans(array $matches): array
    {
        $bans = Db::getActiveBans($this->db);
        Logger::info('Loaded bans', ['count' => count($bans)]);

        $debugBans = (int) (getenv('DEBUG_BANS') ?: 0) === 1;
        $debugLimit = max(1, (int) (getenv('DEBUG_BANS_LIMIT') ?: 3));
        
        $filtered = [];
        $bannedCount = 0;

        foreach ($matches as $m) {
            if (!is_array($m)) {
                continue;
            }

            if ($debugBans && $debugLimit > 0) {
                $this->logBanDebug($m, $bans);
                $debugLimit--;
            }

            $res = BanMatcher::matchAny($bans, $m);
            if ($res['matched']) {
                $bannedCount++;
                $this->logBannedMatch($m, $res);
                continue;
            }

            $filtered[] = $m;
        }

        Logger::info('Bans filter applied', [
            'total_matches' => count($matches),
            'banned' => $bannedCount,
            'to_upsert' => count($filtered),
        ]);

        return [
            'filtered' => $filtered,
            'banned' => $bannedCount,
        ];
    }

    /**
     * Save matches to database.
     *
     * @param array<int, array<string, mixed>> $matches
     * @return array<string, int>
     */
    public function saveMatches(array $matches): array
    {
        return Db::upsertMatches($this->db, $matches);
    }

    /**
     * Log ban debug information.
     *
     * @param array<string, mixed> $match
     * @param array<int, array<string, mixed>> $bans
     */
    private function logBanDebug(array $match, array $bans): void
    {
        foreach ($bans as $banRow) {
            $resDbg = BanMatcher::matchBan($banRow, $match);
            Logger::info('Ban debug', [
                'evid' => $match['evid'] ?? null,
                'match' => [
                    'country' => $match['country'] ?? null,
                    'liga' => $match['liga'] ?? null,
                    'home' => $match['home'] ?? null,
                    'away' => $match['away'] ?? null,
                ],
                'ban_id' => $banRow['id'] ?? null,
                'ban' => [
                    'country' => $banRow['country'] ?? null,
                    'liga' => $banRow['liga'] ?? null,
                    'home' => $banRow['home'] ?? null,
                    'away' => $banRow['away'] ?? null,
                ],
                'matched' => $resDbg['matched'],
                'matched_fields' => $resDbg['fields'] ?? [],
            ]);
        }
    }

    /**
     * Log banned match information.
     *
     * @param array<string, mixed> $match
     * @param array<string, mixed> $result
     */
    private function logBannedMatch(array $match, array $result): void
    {
        $ban = $result['ban'] ?? [];

        Logger::info('Match skipped by ban', [
            'evid' => $match['evid'] ?? null,
            'country' => $match['country'] ?? null,
            'liga' => $match['liga'] ?? null,
            'home' => $match['home'] ?? null,
            'away' => $match['away'] ?? null,
            'ban_id' => $ban['id'] ?? null,
            'ban' => [
                'country' => $ban['country'] ?? null,
                'liga' => $ban['liga'] ?? null,
                'home' => $ban['home'] ?? null,
                'away' => $ban['away'] ?? null,
            ],
            'matched_fields' => $result['fields'] ?? [],
        ]);
    }
}
