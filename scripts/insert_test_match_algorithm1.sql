-- Test match for Algorithm 1
-- This match is designed to pass all Algorithm 1 criteria

INSERT INTO matches (
    evid,
    sgi,
    start_time,
    time,
    match_status,
    country,
    liga,
    home,
    away,
    
    -- Form data (last 5 matches, first half goals)
    ht_match_goals_1,
    ht_match_goals_2,
    
    -- H2H data (last 5 H2H matches, first half goals)
    h2h_ht_match_goals_1,
    h2h_ht_match_goals_2,
    
    -- Live score (0:0 in first half)
    live_ht_hscore,
    live_ht_ascore,
    live_hscore,
    live_ascore,
    
    -- Live statistics (high activity)
    live_shots_on_target_home,
    live_shots_on_target_away,
    live_shots_off_target_home,
    live_shots_off_target_away,
    live_danger_att_home,
    live_danger_att_away,
    live_corner_home,
    live_corner_away,
    live_xg_home,
    live_xg_away,
    live_yellow_cards_home,
    live_yellow_cards_away,
    
    -- Trend data (optional but helps)
    live_trend_has_data,
    live_trend_window_seconds,
    live_trend_shots_total_delta,
    live_trend_shots_on_target_delta,
    live_trend_danger_attacks_delta,
    live_trend_xg_delta,
    
    -- Table data (for league factor in v2)
    table_avg,
    
    -- Metadata
    stats_fetch_status,
    stats_updated_at,
    live_updated_at,
    created_at
) VALUES (
    'TEST_ALG1_001',
    'TEST_SGI_001',
    DATE_SUB(NOW(), INTERVAL 20 MINUTE),
    '20:00',
    '1st Half',
    'Тест',
    'Тестовая лига',
    'Атакующая команда А',
    'Атакующая команда Б',
    
    -- Form: Home scored 7 goals in 1H in last 5 matches, Away scored 6
    7,
    6,
    
    -- H2H: Home scored 3 goals in 1H, Away scored 2 in last 5 H2H
    3,
    2,
    
    -- Current score: 0:0 in first half
    0,
    0,
    0,
    0,
    
    -- Live stats: High activity, 5 shots on target total, 27 dangerous attacks
    3,
    2,
    2,
    1,
    15,
    12,
    3,
    2,
    0.8,
    0.6,
    1,
    0,
    
    -- Trend data: Positive acceleration in last 5 minutes
    1,
    300,
    3,
    2,
    5,
    0.3,
    
    -- League average: 2.5 goals per match
    2.50,
    
    -- Metadata
    'success',
    NOW(),
    NOW(),
    NOW()
);

-- Display the inserted match
SELECT 
    id,
    evid,
    home,
    away,
    time,
    match_status,
    ht_match_goals_1,
    ht_match_goals_2,
    h2h_ht_match_goals_1,
    h2h_ht_match_goals_2,
    live_ht_hscore,
    live_ht_ascore,
    live_shots_on_target_home + live_shots_on_target_away AS shots_on_target_total,
    live_danger_att_home + live_danger_att_away AS dangerous_attacks_total
FROM matches 
WHERE evid = 'TEST_ALG1_001';
