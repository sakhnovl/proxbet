-- Algorithm 1 dual-run divergence report
-- Usage:
--   1. Ensure dual-run payload is stored in matches.live_score_components
--   2. Replace INTERVAL values if you need a different window

-- Window summary: last 1 day / 2 days / 7 days
SELECT
    window_name,
    COUNT(*) AS total_rows,
    SUM(legacy_decision <> v2_decision) AS decision_mismatch,
    SUM(legacy_decision = 'bet' AND v2_decision = 'no_bet') AS legacy_bet_v2_no_bet,
    SUM(legacy_decision = 'no_bet' AND v2_decision = 'bet') AS legacy_no_bet_v2_bet,
    SUM(legacy_decision = v2_decision AND probability_diff >= 0.05) AS same_decision_probability_gap,
    SUM(divergence_level = 'none') AS divergence_none,
    SUM(divergence_level = 'low') AS divergence_low,
    SUM(divergence_level = 'medium') AS divergence_medium,
    SUM(divergence_level = 'high') AS divergence_high
FROM (
    SELECT
        '1d' AS window_name,
        JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.legacy_decision')) AS legacy_decision,
        JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.v2_decision')) AS v2_decision,
        CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.probability_diff')) AS DECIMAL(10,4)) AS probability_diff,
        JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.divergence_level')) AS divergence_level
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.dual_run') IS NOT NULL
      AND COALESCE(live_updated_at, updated_at, created_at) >= DATE_SUB(NOW(), INTERVAL 1 DAY)

    UNION ALL

    SELECT
        '2d' AS window_name,
        JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.legacy_decision')) AS legacy_decision,
        JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.v2_decision')) AS v2_decision,
        CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.probability_diff')) AS DECIMAL(10,4)) AS probability_diff,
        JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.divergence_level')) AS divergence_level
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.dual_run') IS NOT NULL
      AND COALESCE(live_updated_at, updated_at, created_at) >= DATE_SUB(NOW(), INTERVAL 2 DAY)

    UNION ALL

    SELECT
        '7d' AS window_name,
        JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.legacy_decision')) AS legacy_decision,
        JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.v2_decision')) AS v2_decision,
        CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.probability_diff')) AS DECIMAL(10,4)) AS probability_diff,
        JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.divergence_level')) AS divergence_level
    FROM matches
    WHERE live_score_components IS NOT NULL
      AND JSON_EXTRACT(live_score_components, '$.dual_run') IS NOT NULL
      AND COALESCE(live_updated_at, updated_at, created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)
) AS summary_rows
GROUP BY window_name
ORDER BY FIELD(window_name, '1d', '2d', '7d');

-- Detailed divergent rows
SELECT
    id,
    COALESCE(live_updated_at, updated_at, created_at) AS observed_at,
    country,
    liga,
    home,
    away,
    CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.legacy_probability')) AS DECIMAL(10,4)) AS legacy_probability,
    JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.legacy_decision')) AS legacy_decision,
    JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.legacy_reason')) AS legacy_reason,
    CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.v2_probability')) AS DECIMAL(10,4)) AS v2_probability,
    JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.v2_decision')) AS v2_decision,
    JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.v2_reason')) AS v2_reason,
    CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.probability_diff')) AS DECIMAL(10,4)) AS probability_diff,
    JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.divergence_level')) AS divergence_level
FROM matches
WHERE live_score_components IS NOT NULL
  AND JSON_EXTRACT(live_score_components, '$.dual_run') IS NOT NULL
  AND (
      JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.legacy_decision')) <>
      JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.v2_decision'))
      OR CAST(JSON_UNQUOTE(JSON_EXTRACT(live_score_components, '$.dual_run.probability_diff')) AS DECIMAL(10,4)) >= 0.05
  )
ORDER BY COALESCE(live_updated_at, updated_at, created_at) DESC, probability_diff DESC;
