<?php

declare(strict_types=1);

function proxbet_api_json_ok(mixed $data): void
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function proxbet_api_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function proxbet_api_require_env(): void
{
    proxbet_require_env(['DB_HOST', 'DB_USER', 'DB_NAME']);
}

function proxbet_api_handle_request(PDO $pdo, array $query, array $server): void
{
    $action = trim((string) ($query['action'] ?? ''));
    $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');

    if ($method !== 'GET') {
        proxbet_api_json_error('Only GET method is allowed.', 405);
    }

    if ($action === 'get_matches') {
        proxbet_api_handle_get_matches($pdo, $query);
    }

    if ($action === 'get_match_details') {
        proxbet_api_handle_get_match_details($pdo, $query);
    }

    proxbet_api_json_error('Unknown action.', 404);
}

function proxbet_api_format_match_status(?string $time, ?string $matchStatus): string
{
    if ($matchStatus === 'РРіСЂР° Р·Р°РІРµСЂС€РµРЅР°') {
        return 'Р—Р°РІРµСЂС€РµРЅ';
    }

    if ($time !== null && $time !== '') {
        if ($time === '00:00') {
            return 'РќР°С‡Р°Р»Рѕ';
        }

        if (preg_match('/^(\d{1,3}):\d{2}$/', $time, $matches) === 1) {
            return (string) ((int) $matches[1]) . '\'';
        }

        return $time;
    }

    if ($matchStatus !== null && $matchStatus !== '') {
        return $matchStatus === 'РРіСЂР° Р·Р°РІРµСЂС€РµРЅР°' ? 'Р—Р°РІРµСЂС€РµРЅ' : $matchStatus;
    }

    return 'РЎРєРѕСЂРѕ';
}

function proxbet_api_is_match_live(?string $time, ?string $matchStatus): bool
{
    $finishedStatuses = ['РРіСЂР° Р·Р°РІРµСЂС€РµРЅР°', 'Р—Р°РІРµСЂС€РµРЅ', 'РћС‚РјРµРЅРµРЅ', 'РџРµСЂРµРЅРµСЃРµРЅ'];

    if ($time === null || $time === '') {
        return false;
    }

    return !in_array($matchStatus ?? '', $finishedStatuses, true);
}

function proxbet_api_calculate_possession(?float $homeAtt, ?float $awayAtt): array
{
    if ($homeAtt === null || $awayAtt === null || ($homeAtt + $awayAtt) == 0) {
        return ['home' => null, 'away' => null];
    }

    $total = $homeAtt + $awayAtt;
    $homePossession = round(($homeAtt / $total) * 100);

    return ['home' => (int) $homePossession, 'away' => 100 - (int) $homePossession];
}

function proxbet_api_parse_live_minute(?string $time): int
{
    if ($time === null || $time === '') {
        return 0;
    }

    if (preg_match('/^(\d{1,3}):\d{2}$/', $time, $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
}

function proxbet_api_calculate_bet_probability(array $row): ?float
{
    $formHomeGoals = $row['ht_match_goals_1'] !== null ? (int) $row['ht_match_goals_1'] : null;
    $formAwayGoals = $row['ht_match_goals_2'] !== null ? (int) $row['ht_match_goals_2'] : null;
    $h2hHomeGoals = $row['h2h_ht_match_goals_1'] !== null ? (int) $row['h2h_ht_match_goals_1'] : null;
    $h2hAwayGoals = $row['h2h_ht_match_goals_2'] !== null ? (int) $row['h2h_ht_match_goals_2'] : null;

    if ($formHomeGoals === null || $formAwayGoals === null || $h2hHomeGoals === null || $h2hAwayGoals === null) {
        return null;
    }

    $shotsOnTargetHome = (int) ($row['live_shots_on_target_home'] ?? 0);
    $shotsOnTargetAway = (int) ($row['live_shots_on_target_away'] ?? 0);
    $shotsOffTargetHome = (int) ($row['live_shots_off_target_home'] ?? 0);
    $shotsOffTargetAway = (int) ($row['live_shots_off_target_away'] ?? 0);
    $dangerousAttacksHome = (int) ($row['live_danger_att_home'] ?? 0);
    $dangerousAttacksAway = (int) ($row['live_danger_att_away'] ?? 0);
    $cornersHome = (int) ($row['live_corner_home'] ?? 0);
    $cornersAway = (int) ($row['live_corner_away'] ?? 0);

    $shotsOnTarget = $shotsOnTargetHome + $shotsOnTargetAway;
    $shotsTotal = $shotsOnTarget + $shotsOffTargetHome + $shotsOffTargetAway;
    $dangerousAttacks = $dangerousAttacksHome + $dangerousAttacksAway;

    $formScore = (($formHomeGoals / 5.0) + ($formAwayGoals / 5.0)) / 2.0;
    $h2hScore = ($h2hHomeGoals + $h2hAwayGoals) / 10.0;
    $liveScore = proxbet_api_calculate_live_score([
        'shots_total' => $shotsTotal,
        'shots_on_target' => $shotsOnTarget,
        'dangerous_attacks' => $dangerousAttacks,
        'shots_on_target_home' => $shotsOnTargetHome,
        'shots_on_target_away' => $shotsOnTargetAway,
        'shots_off_target_home' => $shotsOffTargetHome,
        'shots_off_target_away' => $shotsOffTargetAway,
        'dangerous_attacks_home' => $dangerousAttacksHome,
        'dangerous_attacks_away' => $dangerousAttacksAway,
        'corners_home' => $cornersHome,
        'corners_away' => $cornersAway,
        'xg_home' => $row['live_xg_home'] !== null ? (float) $row['live_xg_home'] : null,
        'xg_away' => $row['live_xg_away'] !== null ? (float) $row['live_xg_away'] : null,
        'yellow_cards_home' => $row['live_yellow_cards_home'] !== null ? (int) $row['live_yellow_cards_home'] : null,
        'yellow_cards_away' => $row['live_yellow_cards_away'] !== null ? (int) $row['live_yellow_cards_away'] : null,
        'trend_shots_total_delta' => $row['live_trend_shots_total_delta'] !== null ? (int) $row['live_trend_shots_total_delta'] : null,
        'trend_shots_on_target_delta' => $row['live_trend_shots_on_target_delta'] !== null ? (int) $row['live_trend_shots_on_target_delta'] : null,
        'trend_dangerous_attacks_delta' => $row['live_trend_danger_attacks_delta'] !== null ? (int) $row['live_trend_danger_attacks_delta'] : null,
        'trend_xg_delta' => $row['live_trend_xg_delta'] !== null ? (float) $row['live_trend_xg_delta'] : null,
        'trend_window_seconds' => $row['live_trend_window_seconds'] !== null ? (int) $row['live_trend_window_seconds'] : null,
        'has_trend_data' => ((int) ($row['live_trend_has_data'] ?? 0)) === 1,
    ]);

    return $formScore * 0.35 + $h2hScore * 0.15 + $liveScore * 0.50;
}

function proxbet_api_calculate_live_score(array $stats): float
{
    $weights = [];
    $scores = [];

    $weights[] = 0.22;
    $scores[] = proxbet_api_cap(($stats['shots_total'] ?? 0) / 8.0);

    $weights[] = 0.28;
    $scores[] = proxbet_api_cap(($stats['shots_on_target'] ?? 0) / 4.0);

    $weights[] = 0.25;
    $scores[] = proxbet_api_cap(($stats['dangerous_attacks'] ?? 0) / 28.0);

    $weights[] = 0.10;
    $scores[] = proxbet_api_calculate_dominance_score($stats);

    if (($stats['has_trend_data'] ?? false) === true) {
        $weights[] = 0.12;
        $scores[] = proxbet_api_calculate_trend_score($stats);
    }

    if (is_numeric($stats['xg_home'] ?? null) && is_numeric($stats['xg_away'] ?? null)) {
        $weights[] = 0.10;
        $scores[] = proxbet_api_cap((((float) $stats['xg_home']) + ((float) $stats['xg_away'])) / 1.2);
    }

    if (($stats['yellow_cards_home'] ?? null) !== null && ($stats['yellow_cards_away'] ?? null) !== null) {
        $weights[] = 0.05;
        $scores[] = proxbet_api_cap((((int) $stats['yellow_cards_home']) + ((int) $stats['yellow_cards_away'])) / 4.0);
    }

    $weightedSum = 0.0;
    $weightTotal = 0.0;
    foreach ($scores as $index => $score) {
        $weightedSum += $score * $weights[$index];
        $weightTotal += $weights[$index];
    }

    if ($weightTotal <= 0.0) {
        return 0.0;
    }

    return round($weightedSum / $weightTotal, 4);
}

function proxbet_api_calculate_dominance_score(array $stats): float
{
    $homePressure = proxbet_api_build_side_pressure(
        (int) ($stats['shots_on_target_home'] ?? 0),
        (int) ($stats['shots_off_target_home'] ?? 0),
        (int) ($stats['dangerous_attacks_home'] ?? 0),
        (int) ($stats['corners_home'] ?? 0),
        $stats['xg_home'] ?? null
    );
    $awayPressure = proxbet_api_build_side_pressure(
        (int) ($stats['shots_on_target_away'] ?? 0),
        (int) ($stats['shots_off_target_away'] ?? 0),
        (int) ($stats['dangerous_attacks_away'] ?? 0),
        (int) ($stats['corners_away'] ?? 0),
        $stats['xg_away'] ?? null
    );

    $totalPressure = $homePressure + $awayPressure;
    if ($totalPressure <= 0.0) {
        return 0.0;
    }

    return proxbet_api_cap(((max($homePressure, $awayPressure) / $totalPressure) - 0.5) * 2.0);
}

function proxbet_api_calculate_trend_score(array $stats): float
{
    $windowSeconds = is_numeric($stats['trend_window_seconds'] ?? null)
        ? max(1, (int) $stats['trend_window_seconds'])
        : 1;
    $windowFactor = min(1.0, $windowSeconds / 300.0);
    $shotDelta = is_numeric($stats['trend_shots_total_delta'] ?? null)
        ? proxbet_api_cap(((int) $stats['trend_shots_total_delta']) / 4.0)
        : 0.0;
    $shotsOnTargetDelta = is_numeric($stats['trend_shots_on_target_delta'] ?? null)
        ? proxbet_api_cap(((int) $stats['trend_shots_on_target_delta']) / 2.0)
        : 0.0;
    $dangerDelta = is_numeric($stats['trend_dangerous_attacks_delta'] ?? null)
        ? proxbet_api_cap(((int) $stats['trend_dangerous_attacks_delta']) / 10.0)
        : 0.0;
    $xgDelta = is_numeric($stats['trend_xg_delta'] ?? null)
        ? proxbet_api_cap(((float) $stats['trend_xg_delta']) / 0.35)
        : 0.0;

    return proxbet_api_cap((($shotDelta * 0.20) + ($shotsOnTargetDelta * 0.35) + ($dangerDelta * 0.30) + ($xgDelta * 0.15)) * $windowFactor);
}

function proxbet_api_build_side_pressure(
    int $shotsOnTarget,
    int $shotsOffTarget,
    int $dangerousAttacks,
    int $corners,
    mixed $xg
): float {
    $pressure = ($shotsOnTarget * 1.4)
        + ($shotsOffTarget * 0.45)
        + ($dangerousAttacks * 0.08)
        + ($corners * 0.25);

    if (is_numeric($xg)) {
        $pressure += (float) $xg * 2.2;
    }

    return $pressure;
}

function proxbet_api_cap(float $value): float
{
    if ($value < 0.0) {
        return 0.0;
    }

    if ($value > 1.0) {
        return 1.0;
    }

    return $value;
}

function proxbet_api_is_bet_candidate(array $row): bool
{
    if (!proxbet_api_is_match_live($row['time'] ?? null, $row['match_status'] ?? null)) {
        return false;
    }

    $minute = proxbet_api_parse_live_minute($row['time'] ?? null);
    if ($minute < 15 || $minute > 30) {
        return false;
    }

    if ((int) ($row['live_ht_hscore'] ?? 0) !== 0 || (int) ($row['live_ht_ascore'] ?? 0) !== 0) {
        return false;
    }

    $shotsOnTarget = (int) ($row['live_shots_on_target_home'] ?? 0) + (int) ($row['live_shots_on_target_away'] ?? 0);
    $dangerousAttacks = (int) ($row['live_danger_att_home'] ?? 0) + (int) ($row['live_danger_att_away'] ?? 0);

    if ($shotsOnTarget <= 0 || $dangerousAttacks < 20) {
        return false;
    }

    $probability = proxbet_api_calculate_bet_probability($row);

    return $probability !== null && $probability >= 0.65;
}

function proxbet_api_map_match(array $row): array
{
    $isLive = proxbet_api_is_match_live($row['time'] ?? null, $row['match_status'] ?? null);
    $betProbability = proxbet_api_calculate_bet_probability($row);
    $possession = proxbet_api_calculate_possession(
        $row['live_att_home'] !== null ? (float) $row['live_att_home'] : null,
        $row['live_att_away'] !== null ? (float) $row['live_att_away'] : null
    );

    $match = [
        'id' => (int) ($row['id'] ?? 0),
        'evid' => $row['evid'] ?? '',
        'status' => proxbet_api_format_match_status($row['time'] ?? null, $row['match_status'] ?? null),
        'team1' => $row['home'] ?? '',
        'team2' => $row['away'] ?? '',
        'score1' => $row['live_hscore'] !== null ? (string) $row['live_hscore'] : '-',
        'score2' => $row['live_ascore'] !== null ? (string) $row['live_ascore'] : '-',
        'isLive' => $isLive,
        'isBetCandidate' => proxbet_api_is_bet_candidate($row),
    ];

    if (!empty($row['latest_bet_status'])) {
        $match['telegramBetStatus'] = (string) $row['latest_bet_status'];
    }

    if ($betProbability !== null) {
        $match['firstHalfGoalProbability'] = round($betProbability * 100);
    }

    if ($row['home_cf'] !== null || $row['draw_cf'] !== null || $row['away_cf'] !== null) {
        $match['odds'] = [
            'home' => $row['home_cf'] !== null ? (float) $row['home_cf'] : null,
            'draw' => $row['draw_cf'] !== null ? (float) $row['draw_cf'] : null,
            'away' => $row['away_cf'] !== null ? (float) $row['away_cf'] : null,
        ];
    }

    if ($isLive) {
        $shotsHome = (int) ($row['live_shots_on_target_home'] ?? 0) + (int) ($row['live_shots_off_target_home'] ?? 0);
        $shotsAway = (int) ($row['live_shots_on_target_away'] ?? 0) + (int) ($row['live_shots_off_target_away'] ?? 0);

        $match['stats'] = [
            'possession_home' => $possession['home'],
            'possession_away' => $possession['away'],
            'shots_home' => $shotsHome > 0 ? $shotsHome : null,
            'shots_away' => $shotsAway > 0 ? $shotsAway : null,
            'shots_on_target_home' => $row['live_shots_on_target_home'] !== null ? (int) $row['live_shots_on_target_home'] : null,
            'shots_on_target_away' => $row['live_shots_on_target_away'] !== null ? (int) $row['live_shots_on_target_away'] : null,
        ];
    }

    return $match;
}

function proxbet_api_fetch_match_rows(PDO $pdo, string $filter, int $limit): array
{
    $where = '1=1';
    if ($filter === 'live') {
        $where = '`time` IS NOT NULL AND COALESCE(`match_status`, \'\') <> \'РРіСЂР° Р·Р°РІРµСЂС€РµРЅР°\'';
    } elseif ($filter === 'finished') {
        $where = '`match_status` = \'РРіСЂР° Р·Р°РІРµСЂС€РµРЅР°\'';
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
        `live_trend_shots_total_delta`, `live_trend_shots_on_target_delta`,
        `live_trend_danger_attacks_delta`, `live_trend_xg_delta`,
        `live_trend_window_seconds`, `live_trend_has_data`,
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

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function proxbet_api_group_matches_by_league(array $rows): array
{
    $leagues = [];
    $leagueMap = [];

    foreach ($rows as $row) {
        $country = $row['country'] ?? 'Р”СЂСѓРіРёРµ';
        $liga = $row['liga'] ?? 'Р‘РµР· Р»РёРіРё';
        $leagueKey = $country . '|' . $liga;

        if (!isset($leagueMap[$leagueKey])) {
            $leagueMap[$leagueKey] = count($leagues);
            $leagues[] = [
                'league' => $country . ': ' . $liga,
                'country' => $country,
                'matches' => [],
            ];
        }

        $leagues[$leagueMap[$leagueKey]]['matches'][] = proxbet_api_map_match($row);
    }

    return $leagues;
}

function proxbet_api_handle_get_matches(PDO $pdo, array $query): void
{
    $filter = trim((string) ($query['filter'] ?? 'all'));
    $filter = in_array($filter, ['all', 'live', 'finished'], true) ? $filter : 'all';
    $limit = max(1, min(200, (int) ($query['limit'] ?? 100)));

    $rows = proxbet_api_fetch_match_rows($pdo, $filter, $limit);
    $leagues = proxbet_api_group_matches_by_league($rows);

    proxbet_api_json_ok([
        'leagues' => $leagues,
        'updated_at' => date('c'),
        'filter' => $filter,
        'total_matches' => array_sum(array_map(static fn(array $league): int => count($league['matches']), $leagues)),
    ]);
}

function proxbet_api_handle_get_match_details(PDO $pdo, array $query): void
{
    $matchId = (int) ($query['id'] ?? 0);
    if ($matchId <= 0) {
        proxbet_api_json_error('Invalid or missing "id" parameter.');
    }

    $stmt = $pdo->prepare('SELECT * FROM `matches` WHERE `id` = ?');
    $stmt->execute([$matchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        proxbet_api_json_error('Match not found.', 404);
    }

    $match = proxbet_api_map_match($row);
    $match['start_time'] = $row['start_time'];
    $match['match_status'] = $row['match_status'];
    $match['country'] = $row['country'];
    $match['liga'] = $row['liga'];

    if (isset($match['odds'])) {
        $match['odds']['total_line'] = $row['total_line'] !== null ? (float) $row['total_line'] : null;
        $match['odds']['total_over'] = $row['total_line_tb'] !== null ? (float) $row['total_line_tb'] : null;
        $match['odds']['total_under'] = $row['total_line_tm'] !== null ? (float) $row['total_line_tm'] : null;
    }

    if (!empty($match['isLive'])) {
        $match['stats']['xg_home'] = $row['live_xg_home'] !== null ? (float) $row['live_xg_home'] : null;
        $match['stats']['xg_away'] = $row['live_xg_away'] !== null ? (float) $row['live_xg_away'] : null;
        $match['stats']['attacks_home'] = $row['live_att_home'] !== null ? (int) $row['live_att_home'] : null;
        $match['stats']['attacks_away'] = $row['live_att_away'] !== null ? (int) $row['live_att_away'] : null;
        $match['stats']['danger_attacks_home'] = $row['live_danger_att_home'] !== null ? (int) $row['live_danger_att_home'] : null;
        $match['stats']['danger_attacks_away'] = $row['live_danger_att_away'] !== null ? (int) $row['live_danger_att_away'] : null;
        $match['stats']['corners_home'] = $row['live_corner_home'] !== null ? (int) $row['live_corner_home'] : null;
        $match['stats']['corners_away'] = $row['live_corner_away'] !== null ? (int) $row['live_corner_away'] : null;
        $match['stats']['yellow_cards_home'] = $row['live_yellow_cards_home'] !== null ? (int) $row['live_yellow_cards_home'] : null;
        $match['stats']['yellow_cards_away'] = $row['live_yellow_cards_away'] !== null ? (int) $row['live_yellow_cards_away'] : null;
    }

    proxbet_api_json_ok($match);
}
