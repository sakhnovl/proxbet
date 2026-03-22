# AlgorithmX Calibration Guide

This guide explains how to calibrate and optimize AlgorithmX parameters using historical data.

## Overview

AlgorithmX uses several parameters that affect prediction accuracy:
- **k** (sigmoid steepness): Controls how quickly probability changes with AIS_rate
- **threshold** (sigmoid calibration): The AIS_rate value at which probability ≈ 50%
- **AIS weights**: Relative importance of dangerous_attacks, shots, shots_on_target, corners

## Calibration Process

### Step 1: Collect Historical Data

Run the data collection script to gather predictions and actual outcomes:

```bash
php scripts/algorithmx_collect_historical_data.php --limit=500 --from=2026-01-01 --to=2026-03-22
```

**Parameters:**
- `--limit`: Number of matches to analyze (default: 500)
- `--from`: Start date (optional)
- `--to`: End date (optional)
- `--output`: Output file path (default: data/algorithmx_historical_data.json)

**Output:** JSON file containing predictions and actual outcomes for each match.

### Step 2: Generate Calibration Report

Analyze current parameter performance:

```bash
php scripts/algorithmx_report_calibration.php --input=data/algorithmx_historical_data.json
```

**Output:** Markdown report with:
- Brier Score (target: < 0.20)
- ROC-AUC (target: > 0.68)
- Calibration curve
- Probability distribution
- Recommendations

### Step 3: Optimize Parameters

Run grid search to find optimal parameters:

```bash
php scripts/algorithmx_calibrate_parameters.php --input=data/algorithmx_historical_data.json
```

**Process:**
- Tests 125 parameter combinations (5 k values × 5 thresholds × 5 weight sets)
- Evaluates each using Brier Score and ROC-AUC
- Outputs top 5 configurations

**Output:** JSON file with best parameters and full results.

### Step 4: Apply Best Parameters

Update `backend/scanner/Algorithms/AlgorithmX/Config.php` with optimal values:

```php
public const SIGMOID_K = 2.5;              // Update from calibration
public const SIGMOID_THRESHOLD = 1.8;      // Update from calibration

public const WEIGHT_DANGEROUS_ATTACKS = 0.4;  // Update from calibration
public const WEIGHT_SHOTS = 0.3;
public const WEIGHT_SHOTS_ON_TARGET = 0.2;
public const WEIGHT_CORNERS = 0.1;
```

Or use environment variables:

```bash
ALGORITHMX_SIGMOID_K=2.5
ALGORITHMX_SIGMOID_THRESHOLD=1.8
```

### Step 5: Validate

Re-run data collection and report generation with new parameters to confirm improvement.

## Quality Metrics Explained

### Brier Score

Measures accuracy of probabilistic predictions.

**Formula:** Average of (predicted - actual)²

**Interpretation:**
- 0.00 = Perfect predictions
- < 0.10 = Excellent
- < 0.20 = Good (target)
- < 0.30 = Fair
- \> 0.30 = Poor

**Example:**
- Predicted: 0.7, Actual: Goal → (0.7 - 1.0)² = 0.09
- Predicted: 0.3, Actual: No Goal → (0.3 - 0.0)² = 0.09
- Brier Score = (0.09 + 0.09) / 2 = 0.09 ✅

### ROC-AUC

Measures ability to distinguish between goal/no-goal scenarios.

**Interpretation:**
- 1.0 = Perfect discrimination
- > 0.80 = Excellent
- > 0.68 = Good (target)
- > 0.60 = Fair
- 0.5 = Random guessing
- < 0.5 = Worse than random

**How it works:** Counts how often positive cases (goals) have higher predicted probabilities than negative cases (no goals).

### Calibration Curve

Shows how well predicted probabilities match actual frequencies.

**Perfect calibration:** If you predict 60% probability, goals should occur 60% of the time.

**Example:**
| Bin | Predicted | Actual | Status |
|-----|-----------|--------|--------|
| 0.6-0.7 | 0.65 | 0.63 | ✅ Good |
| 0.7-0.8 | 0.75 | 0.55 | ❌ Overestimating |

## Parameter Tuning Guidelines

### Sigmoid K (Steepness)

**Effect:** Controls sensitivity to AIS_rate changes

- **Higher k (3.0-3.5):** More aggressive, sharper transitions
  - Use when: Clear separation between active/inactive matches
  - Risk: May overfit, extreme probabilities
  
- **Lower k (1.5-2.0):** More conservative, gradual transitions
  - Use when: Noisy data, uncertain patterns
  - Risk: May underfit, probabilities too centered

**Symptoms:**
- Brier Score high + probabilities clustered around 0.5 → Increase k
- Many extreme probabilities (< 0.1 or > 0.9) → Decrease k

### Sigmoid Threshold

**Effect:** Shifts the calibration point

- **Higher threshold (2.0-2.3):** Requires more activity for high probability
  - Use when: Model overestimates (predicts too high)
  - Effect: Lowers all probabilities
  
- **Lower threshold (1.2-1.5):** Less activity needed for high probability
  - Use when: Model underestimates (predicts too low)
  - Effect: Raises all probabilities

**Symptoms:**
- Calibration curve shows systematic overestimation → Increase threshold
- Calibration curve shows systematic underestimation → Decrease threshold

### AIS Weights

**Current:** [0.4, 0.3, 0.2, 0.1] (dangerous_attacks, shots, shots_on_target, corners)

**Alternative configurations:**
- **shots_heavy:** [0.3, 0.4, 0.2, 0.1] - Emphasize shot volume
- **quality_focused:** [0.3, 0.2, 0.4, 0.1] - Emphasize shot accuracy
- **danger_heavy:** [0.5, 0.25, 0.15, 0.1] - Emphasize dangerous attacks
- **balanced:** [0.35, 0.25, 0.25, 0.15] - More even distribution

**How to choose:**
- Analyze which statistics correlate best with actual goals
- Use calibration script to test all configurations
- Select based on best combined Brier Score + ROC-AUC

## Troubleshooting

### Problem: Brier Score > 0.30

**Causes:**
- Poor parameter calibration
- Insufficient historical data
- Data quality issues

**Solutions:**
1. Run calibration with more data (limit=1000+)
2. Check for data anomalies (missing fields, incorrect values)
3. Try different weight configurations

### Problem: ROC-AUC < 0.60

**Causes:**
- Model cannot distinguish goal/no-goal scenarios
- Features not predictive
- Random or noisy data

**Solutions:**
1. Verify data quality (are actual outcomes correct?)
2. Consider adding more features (xG, possession, etc.)
3. Check if base rate is too extreme (< 10% or > 90%)

### Problem: Calibration Curve Shows Systematic Bias

**Overestimation (predicted > actual):**
- Increase sigmoid threshold
- Decrease sigmoid k
- Apply global probability scaling factor

**Underestimation (predicted < actual):**
- Decrease sigmoid threshold
- Increase sigmoid k

## Best Practices

1. **Minimum Data:** Use at least 500 matches for calibration
2. **Regular Updates:** Re-calibrate monthly or after significant changes
3. **Validation Split:** Use 70% for calibration, 30% for validation
4. **Monitor Production:** Track metrics on live predictions
5. **A/B Testing:** Test new parameters on subset before full deployment

## Example Workflow

```bash
# 1. Collect data
php scripts/algorithmx_collect_historical_data.php --limit=1000

# 2. Generate initial report
php scripts/algorithmx_report_calibration.php

# 3. If metrics are poor, calibrate
php scripts/algorithmx_calibrate_parameters.php

# 4. Review results
cat data/algorithmx_calibration_results.json | jq '.best_parameters'

# 5. Update Config.php with best parameters

# 6. Re-collect data with new parameters
php scripts/algorithmx_collect_historical_data.php --limit=500

# 7. Validate improvement
php scripts/algorithmx_report_calibration.php

# 8. Deploy to production
```

## Monitoring in Production

After deployment, continuously monitor:

```sql
-- Track prediction accuracy
SELECT 
    DATE(created_at) as date,
    AVG(algorithmx_probability) as avg_probability,
    COUNT(*) as total_predictions,
    SUM(CASE WHEN algorithmx_decision = 1 THEN 1 ELSE 0 END) as bet_signals
FROM matches
WHERE algorithmx_probability IS NOT NULL
GROUP BY DATE(created_at)
ORDER BY date DESC
LIMIT 30;
```

Set up alerts for:
- Sudden changes in average probability
- Spike in bet signals (> 50% of matches)
- Drop in bet signals (< 5% of matches)

## References

- **Brier Score:** [Wikipedia](https://en.wikipedia.org/wiki/Brier_score)
- **ROC-AUC:** [Wikipedia](https://en.wikipedia.org/wiki/Receiver_operating_characteristic)
- **Calibration:** [Scikit-learn Guide](https://scikit-learn.org/stable/modules/calibration.html)
