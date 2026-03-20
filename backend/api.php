<?php

declare(strict_types=1);

/**
 * Public API - Match Data
 * 
 * Endpoints:
 *   GET ?action=get_matches[&filter=all|live|finished&limit=100]
 *   GET ?action=get_match_details&id=123
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────

$rootDir = dirname(__DIR__);

require_once $rootDir . '/backend/line/env.php';
require_once $rootDir . '/backend/line/db.php';
require_once $rootDir . '/backend/cors.php';

use Proxbet\Line\Env;
use Proxbet\Line\Db;

Env::load($rootDir . '/.env');

// ── CORS / Headers ─────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
proxbetHandleCors(['GET', 'OPTIONS'], ['Content-Type']);

// ── Helpers ────────────────────────────────────────────────────────────────

function jsonOk($data)
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Format match status for display
 */
function formatMatchStatus(?string $time, ?string $matchStatus): string
{
    if ($matchStatus === 'Игра завершена') {
        return 'Завершен';
    }

    if ($time !== null && $time !== '') {
        if ($time === '00:00') {
            return 'Начало';
        }

        if (preg_match('/^(\d{1,3}):\d{2}$/', $time, $matches) === 1) {
            return (string) ((int) $matches[1]) . '\'';
        }

        return $time;
    }
    
    if ($matchStatus !== null && $matchStatus !== '') {
        // Shorten common statuses
        if ($matchStatus === 'Игра завершена') {
            return 'Завершен';
        }
        return $matchStatus;
    }
    
    return 'Скоро';
}

/**
 * Check if match is currently live
 */
function isMatchLive(?string $time, ?string $matchStatus): bool
{
    if ($matchStatus === 'Игра завершена' || $matchStatus === 'Завершен' || $matchStatus === 'Отменен' || $matchStatus === 'Перенесен') {
        return false;
    }

    if ($time === null || $time === '') {
        return false;
    }
    
    $finishedStatuses = ['Игра завершена', 'Завершен', 'Отменен', 'Перенесен'];
    
    return !in_array($matchStatus ?? '', $finishedStatuses, true);
}

/**
 * Calculate possession percentage from attacks
 */
function calculatePossession(?float $homeAtt, ?float $awayAtt): array
{
    if ($homeAtt === null || $awayAtt === null || ($homeAtt + $awayAtt) == 0) {
        return ['home' => null, 'away' => null];
    }
    
    $total = $homeAtt + $awayAtt;
    $homePossession = round(($homeAtt / $total) * 100);
    $awayPossession = 100 - $homePossession;
    
    return ['home' => (int)$homePossession, 'away' => (int)$awayPossession];
}

function parseLiveMinute(?string $time): int
{
    if ($time === null || $time === '') {
        return 0;
    }

    if (preg_match('/^(\d{1,3}):\d{2}$/', $time, $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
}

function calculateBetProbability(array $row): ?float
{
    $formHomeGoals = $row['ht_match_goals_1'] !== null ? (int) $row['ht_match_goals_1'] : null;
    $formAwayGoals = $row['ht_match_goals_2'] !== null ? (int) $row['ht_match_goals_2'] : null;
    $h2hHomeGoals = $row['h2h_ht_match_goals_1'] !== null ? (int) $row['h2h_ht_match_goals_1'] : null;
    $h2hAwayGoals = $row['h2h_ht_match_goals_2'] !== null ? (int) $row['h2h_ht_match_goals_2'] : null;

    if ($formHomeGoals === null || $formAwayGoals === null || $h2hHomeGoals === null || $h2hAwayGoals === null) {
        return null;
    }

    $shotsOnTarget = 0;
    if ($row['live_shots_on_target_home'] !== null) {
        $shotsOnTarget += (int) $row['live_shots_on_target_home'];
    }
    if ($row['live_shots_on_target_away'] !== null) {
        $shotsOnTarget += (int) $row['live_shots_on_target_away'];
    }

    $shotsTotal = $shotsOnTarget;
    if ($row['live_shots_off_target_home'] !== null) {
        $shotsTotal += (int) $row['live_shots_off_target_home'];
    }
    if ($row['live_shots_off_target_away'] !== null) {
        $shotsTotal += (int) $row['live_shots_off_target_away'];
    }

    $dangerousAttacks = 0;
    if ($row['live_danger_att_home'] !== null) {
        $dangerousAttacks += (int) $row['live_danger_att_home'];
    }
    if ($row['live_danger_att_away'] !== null) {
        $dangerousAttacks += (int) $row['live_danger_att_away'];
    }

    $formScore = (($formHomeGoals / 5.0) + ($formAwayGoals / 5.0)) / 2.0;
    $h2hScore = ($h2hHomeGoals + $h2hAwayGoals) / 10.0;

    if ($shotsTotal >= 6 && $shotsOnTarget >= 2 && $dangerousAttacks >= 20) {
        $liveScore = 0.8;
    } elseif ($shotsTotal >= 4 && $dangerousAttacks >= 15) {
        $liveScore = 0.6;
    } elseif ($shotsTotal >= 2) {
        $liveScore = 0.4;
    } else {
        $liveScore = 0.2;
    }

    return $formScore * 0.4 + $h2hScore * 0.2 + $liveScore * 0.4;
}

function isBetCandidate(array $row): bool
{
    if (!isMatchLive($row['time'] ?? null, $row['match_status'] ?? null)) {
        return false;
    }

    $minute = parseLiveMinute($row['time'] ?? null);
    if ($minute < 15 || $minute > 30) {
        return false;
    }

    $htHomeScore = $row['live_ht_hscore'] !== null ? (int) $row['live_ht_hscore'] : 0;
    $htAwayScore = $row['live_ht_ascore'] !== null ? (int) $row['live_ht_ascore'] : 0;
    if ($htHomeScore !== 0 || $htAwayScore !== 0) {
        return false;
    }

    $shotsOnTarget = 0;
    if ($row['live_shots_on_target_home'] !== null) {
        $shotsOnTarget += (int) $row['live_shots_on_target_home'];
    }
    if ($row['live_shots_on_target_away'] !== null) {
        $shotsOnTarget += (int) $row['live_shots_on_target_away'];
    }

    $dangerousAttacks = 0;
    if ($row['live_danger_att_home'] !== null) {
        $dangerousAttacks += (int) $row['live_danger_att_home'];
    }
    if ($row['live_danger_att_away'] !== null) {
        $dangerousAttacks += (int) $row['live_danger_att_away'];
    }

    if ($shotsOnTarget <= 0 || $dangerousAttacks < 20) {
        return false;
    }

    $probability = calculateBetProbability($row);
    if ($probability === null) {
        return false;
    }

    return $probability >= 0.65;
}

/**
 * Map database row to API response format
 */
function mapMatchToApiResponse(array $row): array
{
    $isLive = isMatchLive($row['time'], $row['match_status']);
    $betProbability = calculateBetProbability($row);
    $possession = calculatePossession(
        $row['live_att_home'] !== null ? (float)$row['live_att_home'] : null,
        $row['live_att_away'] !== null ? (float)$row['live_att_away'] : null
    );
    
    $match = [
        'id' => (int)$row['id'],
        'evid' => $row['evid'] ?? '',
        'status' => formatMatchStatus($row['time'], $row['match_status']),
        'team1' => $row['home'] ?? '',
        'team2' => $row['away'] ?? '',
        'score1' => $row['live_hscore'] !== null ? (string)$row['live_hscore'] : '-',
        'score2' => $row['live_ascore'] !== null ? (string)$row['live_ascore'] : '-',
        'isLive' => $isLive,
        'isBetCandidate' => isBetCandidate($row),
    ];

    if (isset($row['latest_bet_status']) && $row['latest_bet_status'] !== null && $row['latest_bet_status'] !== '') {
        $match['telegramBetStatus'] = (string)$row['latest_bet_status'];
    }

    if ($betProbability !== null) {
        $match['firstHalfGoalProbability'] = round($betProbability * 100);
    }
    
    // Add odds if available
    if ($row['home_cf'] !== null || $row['draw_cf'] !== null || $row['away_cf'] !== null) {
        $match['odds'] = [
            'home' => $row['home_cf'] !== null ? (float)$row['home_cf'] : null,
            'draw' => $row['draw_cf'] !== null ? (float)$row['draw_cf'] : null,
            'away' => $row['away_cf'] !== null ? (float)$row['away_cf'] : null,
        ];
    }
    
    // Add stats if live match has data
    if ($isLive) {
        $shotsHome = 0;
        $shotsAway = 0;
        
        if ($row['live_shots_on_target_home'] !== null) {
            $shotsHome += (int)$row['live_shots_on_target_home'];
        }
        if ($row['live_shots_off_target_home'] !== null) {
            $shotsHome += (int)$row['live_shots_off_target_home'];
        }
        if ($row['live_shots_on_target_away'] !== null) {
            $shotsAway += (int)$row['live_shots_on_target_away'];
        }
        if ($row['live_shots_off_target_away'] !== null) {
            $shotsAway += (int)$row['live_shots_off_target_away'];
        }
        
        $match['stats'] = [
            'possession_home' => $possession['home'],
            'possession_away' => $possession['away'],
            'shots_home' => $shotsHome > 0 ? $shotsHome : null,
            'shots_away' => $shotsAway > 0 ? $shotsAway : null,
            'shots_on_target_home' => $row['live_shots_on_target_home'] !== null ? (int)$row['live_shots_on_target_home'] : null,
            'shots_on_target_away' => $row['live_shots_on_target_away'] !== null ? (int)$row['live_shots_on_target_away'] : null,
        ];
    }
    
    return $match;
}

// ── Database Connection ────────────────────────────────────────────────────

try {
    $pdo = Db::connectFromEnv();
} catch (\Throwable $e) {
    jsonError('Database connection failed: ' . $e->getMessage(), 503);
}

// ── Router ─────────────────────────────────────────────────────────────────

$action = trim($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonError('Only GET method is allowed.', 405);
}

if ($action === 'get_matches') {
    handleGetMatches($pdo);
} elseif ($action === 'get_match_details') {
    handleGetMatchDetails($pdo);
} else {
    jsonError('Unknown action.', 404);
}

// ── Handlers ───────────────────────────────────────────────────────────────

function handleGetMatches(PDO $pdo)
{
    $filter = trim($_GET['filter'] ?? 'all');
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    
    if (!in_array($filter, ['all', 'live', 'finished'], true)) {
        $filter = 'all';
    }
    
    // Build WHERE clause based on filter
    $where = '1=1';
    $params = [];
    
    if ($filter === 'live') {
        $where = '`time` IS NOT NULL AND COALESCE(`match_status`, \'\') <> \'Игра завершена\'';
    } elseif ($filter === 'finished') {
        $where = '`match_status` = \'Игра завершена\'';
    }
    
    $sql = 'SELECT 
        `id`, `evid`, `time`, `match_status`, `start_time`,
        `country`, `liga`, `home`, `away`,
        `home_cf`, `draw_cf`, `away_cf`,
        `live_hscore`, `live_ascore`, `live_ht_hscore`, `live_ht_ascore`,
        `live_xg_home`, `live_xg_away`,
        `live_att_home`, `live_att_away`,
        `live_danger_att_home`, `live_danger_att_away`,
        `live_shots_on_target_home`, `live_shots_on_target_away`,
        `live_shots_off_target_home`, `live_shots_off_target_away`,
        `live_corner_home`, `live_corner_away`,
        `live_yellow_cards_home`, `live_yellow_cards_away`,
        `ht_match_goals_1`, `ht_match_missed_goals_1`,
        `ht_match_goals_2`, `ht_match_missed_goals_2`,
        `h2h_ht_match_goals_1`, `h2h_ht_match_goals_2`,
        (
            SELECT bm.`bet_status`
            FROM `bet_messages` bm
            WHERE bm.`match_id` = `matches`.`id`
            ORDER BY bm.`sent_at` DESC, bm.`id` DESC
            LIMIT 1
        ) AS `latest_bet_status`,
        `live_updated_at`
    FROM `matches`
    WHERE ' . $where . '
    ORDER BY 
        CASE WHEN `time` IS NOT NULL THEN 0 ELSE 1 END,
        `start_time` DESC
    LIMIT ?';
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group matches by league
    $leagues = [];
    $leagueMap = [];
    
    foreach ($rows as $row) {
        $country = $row['country'] ?? 'Другие';
        $liga = $row['liga'] ?? 'Без лиги';
        $leagueKey = $country . '|' . $liga;
        
        if (!isset($leagueMap[$leagueKey])) {
            $leagueMap[$leagueKey] = count($leagues);
            $leagues[] = [
                'league' => $country . ': ' . $liga,
                'country' => $country,
                'matches' => []
            ];
        }
        
        $leagueIndex = $leagueMap[$leagueKey];
        $leagues[$leagueIndex]['matches'][] = mapMatchToApiResponse($row);
    }
    
    jsonOk([
        'leagues' => $leagues,
        'updated_at' => date('c'),
        'filter' => $filter,
        'total_matches' => array_sum(array_map(fn($l) => count($l['matches']), $leagues))
    ]);
}

function handleGetMatchDetails(PDO $pdo)
{
    $matchId = (int)($_GET['id'] ?? 0);
    
    if ($matchId <= 0) {
        jsonError('Invalid or missing "id" parameter.');
    }
    
    $sql = 'SELECT * FROM `matches` WHERE `id` = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$matchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!is_array($row)) {
        jsonError('Match not found.', 404);
    }
    
    $match = mapMatchToApiResponse($row);
    
    // Add additional details for detail view
    $match['start_time'] = $row['start_time'];
    $match['match_status'] = $row['match_status'];
    $match['country'] = $row['country'];
    $match['liga'] = $row['liga'];
    
    // Add extended odds
    if (isset($match['odds'])) {
        $match['odds']['total_line'] = $row['total_line'] !== null ? (float)$row['total_line'] : null;
        $match['odds']['total_over'] = $row['total_line_tb'] !== null ? (float)$row['total_line_tb'] : null;
        $match['odds']['total_under'] = $row['total_line_tm'] !== null ? (float)$row['total_line_tm'] : null;
    }
    
    // Add extended live stats
    if ($match['isLive']) {
        $match['stats']['xg_home'] = $row['live_xg_home'] !== null ? (float)$row['live_xg_home'] : null;
        $match['stats']['xg_away'] = $row['live_xg_away'] !== null ? (float)$row['live_xg_away'] : null;
        $match['stats']['attacks_home'] = $row['live_att_home'] !== null ? (int)$row['live_att_home'] : null;
        $match['stats']['attacks_away'] = $row['live_att_away'] !== null ? (int)$row['live_att_away'] : null;
        $match['stats']['danger_attacks_home'] = $row['live_danger_att_home'] !== null ? (int)$row['live_danger_att_home'] : null;
        $match['stats']['danger_attacks_away'] = $row['live_danger_att_away'] !== null ? (int)$row['live_danger_att_away'] : null;
        $match['stats']['corners_home'] = $row['live_corner_home'] !== null ? (int)$row['live_corner_home'] : null;
        $match['stats']['corners_away'] = $row['live_corner_away'] !== null ? (int)$row['live_corner_away'] : null;
        $match['stats']['yellow_cards_home'] = $row['live_yellow_cards_home'] !== null ? (int)$row['live_yellow_cards_home'] : null;
        $match['stats']['yellow_cards_away'] = $row['live_yellow_cards_away'] !== null ? (int)$row['live_yellow_cards_away'] : null;
    }
    
    jsonOk($match);
}
