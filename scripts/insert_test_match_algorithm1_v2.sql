-- Test match for Algorithm 1 V2
-- This match is designed to pass all Algorithm 1 V2 gating conditions and generate a signal

-- Delete existing test match if exists
DELETE FROM matches WHERE evid = 'TEST_ALG1_V2_001';

-- Insert test match
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
    -- Home: 8 goals in 1H, Away: 7 goals in 1H
    ht_match_goals_1,
    ht_match_goals_2,
    
    -- H2H data (last 5 H2H matches, first half goals)
    -- Home: 4 goals in 1H, Away: 3 goals in 1H
    h2h_ht_match_goals_1,
    h2h_ht_match_goals_2,
    
    -- Live score (0:0 in first half - REQUIRED)
    live_ht_hscore,
    live_ht_ascore,
    live_hscore,
    live_ascore,
    
    -- Live statistics
    -- Shots on target: 4 home + 3 away = 7 total (>= 1 required)
    -- Total shots: 12 (accuracy 58% > 25% threshold)
    -- Dangerous attacks: 18 home + 17 away = 35 total
    -- Attack tempo: 35 / 22 = 1.59 attacks/min (>= 1.5 required)
    live_shots_on_target_home,
    live_shots_on_target_away,
    live_shots_off_target_home,
    live_shots_off_target_away,
    live_danger_att_home,
    live_danger_att_away,
    live_corner_home,
    live_corner_away,
    
    -- xG data (for v2 components)
    -- Total xG: 0.9 + 0.7 = 1.6
    live_xg_home,
    live_xg_away,
    
    -- Cards (no red cards, 1 yellow - minimal penalty)
    live_yellow_cards_home,
    live_yellow_cards_away,
    live_red_cards_home,
    live_red_cards_away,
    
    -- Trend data (for v2 trend acceleration component)
    -- Positive trends in last 5 minutes (300 seconds)
    live_trend_has_data,
    live_trend_window_seconds,
    live_trend_shots_total_delta,
    live_trend_shots_on_target_delta,
    live_trend_danger_attacks_delta,
    live_trend_xg_delta,
    
    -- League data (for v2 league factor)
    -- Average 2.6 goals per match (slightly above baseline 2.5)
    table_avg,
    
    -- Metadata
    stats_fetch_status,
    stats_updated_at,
    live_updated_at,
    created_at
) VALUES (
    'TEST_ALG1_V2_001',
    'TEST_SGI_V2_001',
    DATE_SUB(NOW(), INTERVAL 22 MINUTE),
    '22:00',                    -- Minute 22 (within 15-30 range)
    '1-й тайм',                 -- First half status
    'Тест',
    'Тестовая Премьер Лига',
    'Атакующие Тигры',
    'Быстрые Львы',
    
    -- Form data
    8,                          -- home_goals in 1H (last 5 matches)
    7,                          -- away_goals in 1H (last 5 matches)
    
    -- H2H data
    4,                          -- home_goals in 1H (H2H)
    3,                          -- away_goals in 1H (H2H)
    
    -- Live score: 0:0
    0,                          -- live_ht_hscore
    0,                          -- live_ht_ascore
    0,                          -- live_hscore
    0,                          -- live_ascore
    
    -- Live statistics
    4,                          -- shots_on_target_home
    3,                          -- shots_on_target_away
    3,                          -- shots_off_target_home
    2,                          -- shots_off_target_away
    18,                         -- danger_att_home
    17,                         -- danger_att_away
    4,                          -- corner_home
    3,                          -- corner_away
    0.9,                        -- xg_home
    0.7,                        -- xg_away
    1,                          -- yellow_cards_home
    0,                          -- yellow_cards_away
    0,                          -- red_cards_home
    0,                          -- red_cards_away
    
    -- Trend data
    1,                          -- has_trend_data
    300,                        -- window_seconds (5 minutes)
    5,                          -- shots_total_delta
    3,                          -- shots_on_target_delta
    12,                         -- danger_attacks_delta
    0.4,                        -- xg_delta
    
    -- League data
    2.60,                       -- table_avg
    
    -- Metadata
    'success',
    NOW(),
    NOW(),
    NOW()
);

-- Verify the inserted match
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
WHERE evid = 'TEST_ALG1_V2_001';

-- Expected results for Algorithm 1 V2:
-- ✅ Form data: home=8, away=7 (has_data=true)
-- ✅ H2H data: home=4, away=3 (has_data=true)
-- ✅ Score 0:0 in first half
-- ✅ Minute 22 (within 15-30 range)
-- ✅ Shots on target: 7 (>= 1)
-- ✅ Attack tempo: 35/22 = 1.59 (>= 1.5)
-- ✅ Shot accuracy: 7/12 = 58% (>= 25%)
-- ✅ No ineffective pressure (both teams have >= 2 shots on target)
-- ✅ Probability should be >= 55%
-- 
-- Expected signal: BET = TRUE
