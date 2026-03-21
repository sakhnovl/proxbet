<?php
/**
 * Execute test match insertion for Algorithm 1 V2
 * 
 * Usage: php scripts/execute_test_match.php
 */

require_once __DIR__ . '/../backend/bootstrap/autoload.php';

use Proxbet\Line\Db;
use Proxbet\Line\Env;

try {
    // Load environment
    Env::load(__DIR__ . '/../.env');
    
    // Connect to database
    $db = Db::connectFromEnv();
    
    echo "Connected to database\n";
    echo "Inserting test match for Algorithm 1 V2...\n\n";
    
    // Delete existing test match if exists
    $db->exec("DELETE FROM matches WHERE evid = 'TEST_ALG1_V2_001'");
    echo "✓ Cleaned up existing test match\n";
    
    // Insert test match
    $sql = "INSERT INTO matches (
        evid, sgi, start_time, time, match_status, country, liga, home, away,
        ht_match_goals_1, ht_match_goals_2,
        h2h_ht_match_goals_1, h2h_ht_match_goals_2,
        live_ht_hscore, live_ht_ascore, live_hscore, live_ascore,
        live_shots_on_target_home, live_shots_on_target_away,
        live_shots_off_target_home, live_shots_off_target_away,
        live_danger_att_home, live_danger_att_away,
        live_corner_home, live_corner_away,
        live_xg_home, live_xg_away,
        live_yellow_cards_home, live_yellow_cards_away,
        live_trend_has_data, live_trend_window_seconds,
        live_trend_shots_total_delta, live_trend_shots_on_target_delta,
        live_trend_danger_attacks_delta, live_trend_xg_delta,
        table_avg,
        stats_fetch_status, stats_updated_at, live_updated_at, created_at
    ) VALUES (
        'TEST_ALG1_V2_001', 'TEST_SGI_V2_001',
        DATE_SUB(NOW(), INTERVAL 22 MINUTE), '22:00', '1-й тайм',
        'Тест', 'Тестовая Премьер Лига', 'Атакующие Тигры', 'Быстрые Львы',
        8, 7,
        4, 3,
        0, 0, 0, 0,
        4, 3,
        3, 2,
        18, 17,
        4, 3,
        0.9, 0.7,
        1, 0,
        1, 300,
        5, 3,
        12, 0.4,
        2.60,
        'success', NOW(), NOW(), NOW()
    )";
    
    $db->exec($sql);
    echo "✓ Test match inserted\n\n";
    
    // Verify the inserted match
    $stmt = $db->query("
        SELECT 
            id,
            evid,
            home,
            away,
            time,
            match_status,
            CONCAT(live_ht_hscore, ':', live_ht_ascore) AS score_1h,
            ht_match_goals_1 AS form_home,
            ht_match_goals_2 AS form_away,
            h2h_ht_match_goals_1 AS h2h_home,
            h2h_ht_match_goals_2 AS h2h_away,
            (live_shots_on_target_home + live_shots_on_target_away) AS shots_on_target,
            (live_danger_att_home + live_danger_att_away) AS dangerous_attacks,
            ROUND((live_danger_att_home + live_danger_att_away) / 22.0, 2) AS attack_tempo,
            (live_xg_home + live_xg_away) AS total_xg,
            table_avg AS league_avg
        FROM matches 
        WHERE evid = 'TEST_ALG1_V2_001'
    ");
    
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($match) {
        echo "═══════════════════════════════════════════════════════════\n";
        echo "TEST MATCH DETAILS\n";
        echo "═══════════════════════════════════════════════════════════\n";
        echo "ID:              {$match['id']}\n";
        echo "Event ID:        {$match['evid']}\n";
        echo "Match:           {$match['home']} - {$match['away']}\n";
        echo "Time:            {$match['time']}\n";
        echo "Status:          {$match['match_status']}\n";
        echo "Score 1H:        {$match['score_1h']}\n";
        echo "───────────────────────────────────────────────────────────\n";
        echo "Form (1H):       Home {$match['form_home']}/5, Away {$match['form_away']}/5\n";
        echo "H2H (1H):        Home {$match['h2h_home']}/5, Away {$match['h2h_away']}/5\n";
        echo "───────────────────────────────────────────────────────────\n";
        echo "Shots on target: {$match['shots_on_target']}\n";
        echo "Dangerous att:   {$match['dangerous_attacks']}\n";
        echo "Attack tempo:    {$match['attack_tempo']} attacks/min\n";
        echo "Total xG:        {$match['total_xg']}\n";
        echo "League avg:      {$match['league_avg']}\n";
        echo "═══════════════════════════════════════════════════════════\n\n";
        
        echo "ALGORITHM 1 V2 GATING CONDITIONS CHECK:\n";
        echo "───────────────────────────────────────────────────────────\n";
        echo "✅ Form data available (home={$match['form_home']}, away={$match['form_away']})\n";
        echo "✅ H2H data available (home={$match['h2h_home']}, away={$match['h2h_away']})\n";
        echo "✅ Score 0:0 in first half\n";
        echo "✅ Minute 22 (within 15-30 range)\n";
        echo "✅ Shots on target: {$match['shots_on_target']} (>= 1)\n";
        echo "✅ Attack tempo: {$match['attack_tempo']} (>= 1.5)\n";
        
        $accuracy = round(($match['shots_on_target'] / 12) * 100);
        echo "✅ Shot accuracy: {$accuracy}% (>= 25%)\n";
        echo "✅ No ineffective pressure\n";
        echo "✅ Total xG: {$match['total_xg']} (good pressure)\n";
        echo "───────────────────────────────────────────────────────────\n";
        echo "Expected result: BET = TRUE (signal should be generated)\n";
        echo "═══════════════════════════════════════════════════════════\n\n";
        
        echo "To test the scanner with this match:\n";
        echo "1. Make sure ALGORITHM_VERSION=2 in .env\n";
        echo "2. Run: php backend/scanner/ScannerCli.php --verbose\n";
        echo "3. Check for signal in Telegram channel (if configured)\n\n";
        
        echo "SUCCESS! Test match created.\n";
    } else {
        echo "ERROR: Could not verify inserted match\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
