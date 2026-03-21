-- Algorithm 1 v2 gating impact report
-- Uses live_score_components payload persisted by Scanner for v2 runs.

-- Summary by implemented Stage 4 filters
SELECT
    metric_name,
    metric_type,
    affected_rows,
    bet_rows,
    no_bet_rows,
    avg_penalty_factor
FROM (
    SELECT
        'no_form_data' AS metric_name,
        'gating_reject' AS metric_type,
        COUNT(*) AS affected_rows,
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) = 'probability_threshold_met') AS bet_rows,
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) <> 'probability_threshold_met') AS no_bet_rows,
        NULL AS avg_penalty_factor
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.algorithm_version') = 2
      AND JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.gating_reason')) = 'no_form_data'

    UNION ALL

    SELECT
        'insufficient_shots_on_target',
        'gating_reject',
        COUNT(*),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) = 'probability_threshold_met'),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) <> 'probability_threshold_met'),
        NULL
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.algorithm_version') = 2
      AND JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.gating_reason')) = 'insufficient_shots_on_target'

    UNION ALL

    SELECT
        'missing_h2h',
        'penalty',
        COUNT(*),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) = 'probability_threshold_met'),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) <> 'probability_threshold_met'),
        AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.penalties.missing_h2h')) AS DECIMAL(10,4)))
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.algorithm_version') = 2
      AND JSON_EXTRACT(live_score_components, '$.penalties.missing_h2h') IS NOT NULL

    UNION ALL

    SELECT
        'soft_attack_tempo',
        'penalty',
        COUNT(*),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) = 'probability_threshold_met'),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) <> 'probability_threshold_met'),
        AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.penalties.soft_attack_tempo')) AS DECIMAL(10,4)))
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.algorithm_version') = 2
      AND JSON_EXTRACT(live_score_components, '$.penalties.soft_attack_tempo') IS NOT NULL

    UNION ALL

    SELECT
        'early_shots_relief',
        'penalty',
        COUNT(*),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) = 'probability_threshold_met'),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) <> 'probability_threshold_met'),
        AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.penalties.early_shots_relief')) AS DECIMAL(10,4)))
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.algorithm_version') = 2
      AND JSON_EXTRACT(live_score_components, '$.penalties.early_shots_relief') IS NOT NULL

    UNION ALL

    SELECT
        'low_accuracy',
        'penalty',
        COUNT(*),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) = 'probability_threshold_met'),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) <> 'probability_threshold_met'),
        AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.penalties.low_accuracy')) AS DECIMAL(10,4)))
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.algorithm_version') = 2
      AND JSON_EXTRACT(live_score_components, '$.penalties.low_accuracy') IS NOT NULL

    UNION ALL

    SELECT
        'ineffective_pressure',
        'penalty',
        COUNT(*),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) = 'probability_threshold_met'),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) <> 'probability_threshold_met'),
        AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.penalties.ineffective_pressure')) AS DECIMAL(10,4)))
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.algorithm_version') = 2
      AND JSON_EXTRACT(live_score_components, '$.penalties.ineffective_pressure') IS NOT NULL

    UNION ALL

    SELECT
        'xg_mismatch',
        'red_flag',
        COUNT(*),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) = 'probability_threshold_met'),
        SUM(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) <> 'probability_threshold_met'),
        NULL
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.algorithm_version') = 2
      AND JSON_CONTAINS(JSON_EXTRACT(live_score_components, '$.red_flags'), JSON_QUOTE('xg_mismatch'))
) AS impact_rows
WHERE affected_rows > 0
ORDER BY affected_rows DESC, metric_name ASC;

-- Recent rows affected by gating/penalties/red flags
SELECT
    id,
    COALESCE(live_updated_at, updated_at, created_at) AS observed_at,
    country,
    liga,
    home,
    away,
    JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.gating_reason')) AS gating_reason,
    JSON_EXTRACT(live_score_components, '$.penalties') AS penalties,
    JSON_EXTRACT(live_score_components, '$.red_flags') AS red_flags,
    JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.decision_reason')) AS decision_reason,
    CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.probability')) AS DECIMAL(10,4)) AS probability
FROM matches
WHERE live_score_components IS NOT NULL
  AND JSON_EXTRACT(live_score_components, '$.algorithm_version') = 2
  AND (
      JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.gating_reason')) <> ''
      OR JSON_EXTRACT(live_score_components, '$.penalties') IS NOT NULL
      OR JSON_EXTRACT(live_score_components, '$.red_flags') IS NOT NULL
  )
ORDER BY COALESCE(live_updated_at, updated_at, created_at) DESC, id DESC;
