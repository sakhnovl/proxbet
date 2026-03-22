# AlgorithmX: Goal Probability Algorithm

## Overview

AlgorithmX is a statistical algorithm that predicts the probability of a goal being scored in the remaining time of the first half based on live match statistics. Unlike traditional betting algorithms that rely on historical form and head-to-head data, AlgorithmX focuses exclusively on real-time match dynamics.

**Algorithm ID:** 4  
**Version:** 1.0  
**Status:** Implementation Phase

## Core Concept

The algorithm calculates an **Attack Intensity Score (AIS)** from live statistics and converts it into a probability using a sigmoid function, then applies contextual modifiers based on time remaining, current score, and match dynamics.

## Algorithm Flow

```
Match Data → Extract Live Stats → Validate → Calculate AIS → 
→ Sigmoid Transform → Apply Modifiers → Decision Filter → Bet/No Bet
```

### Step-by-Step Process

1. **Data Extraction**: Extract live statistics from match record
2. **Validation**: Ensure data quality and completeness
3. **AIS Calculation**: Compute attack intensity for both teams
4. **Base Probability**: Apply sigmoid function to AIS rate
5. **Time Modifier**: Adjust for remaining time in first half
6. **Score Modifier**: Adjust based on current score situation
7. **Dry Period Check**: Apply penalty if 0:0 after 30 minutes
8. **Decision**: Compare probability against thresholds

## Mathematical Model

### Attack Intensity Score (AIS)

For each team:
```
AIS = (dangerous_attacks × 0.4) + (shots × 0.3) + 
      (shots_on_target × 0.2) + (corners × 0.1)
```

Total match intensity:
```
AIS_total = AIS_home + AIS_away
AIS_rate = AIS_total / minute
```

### Base Probability (Sigmoid Function)

```
base_prob = 1 / (1 + e^(-k × (AIS_rate - threshold)))
```

Where:
- `k = 2.5` (steepness parameter)
- `threshold = 1.8` (calibration point for ~50% probability)

### Time Factor

```
time_remaining = 45 - minute
time_factor = time_remaining / 45
prob_adjusted = base_prob × (0.4 + 0.6 × time_factor)
```

This ensures that even at minute 44, there's still 40% of the base probability.

### Score Modifier

| Situation | Modifier | Reasoning |
|-----------|----------|-----------|
| Draw (0:0, 1:1, etc.) | ×1.05 | Both teams motivated to score |
| 1 goal difference | ×1.10 | Losing team increases pressure |
| 2+ goal difference | ×0.90 | Winning team defends lead |

### Dry Period Modifier

If `score_home == 0 AND score_away == 0 AND minute > 30`:
```
prob_final × 0.92
```

Teams have found defensive balance, reducing goal likelihood.

### Probability Bounds

Final probability is clamped to realistic range:
```
prob_final = clamp(probability, 0.03, 0.97)  // 3% - 97%
```

## Input Data

The algorithm requires the following live statistics:

```php
[
    'minute' => int,                    // Current minute (5-45)
    'score_home' => int,                // Home team goals
    'score_away' => int,                // Away team goals
    'dangerous_attacks_home' => int,    // Home dangerous attacks
    'dangerous_attacks_away' => int,    // Away dangerous attacks
    'shots_home' => int,                // Home total shots
    'shots_away' => int,                // Away total shots
    'shots_on_target_home' => int,      // Home shots on target
    'shots_on_target_away' => int,      // Away shots on target
    'corners_home' => int,              // Home corners
    'corners_away' => int,              // Away corners
    'match_status' => string,           // Match status
    'has_data' => bool,                 // Data availability flag
]
```

## Output Format

```php
[
    'bet' => bool,              // Betting decision
    'reason' => string,         // Decision reasoning
    'confidence' => float,      // Probability (0.0-1.0)
    'debug' => [
        'ais_home' => float,
        'ais_away' => float,
        'ais_total' => float,
        'ais_rate' => float,
        'base_prob' => float,
        'time_remaining' => int,
        'time_factor' => float,
        'prob_adjusted' => float,
        'score_modifier' => float,
        'dry_period_applied' => bool,
        'prob_final' => float,
        'interpretation' => string,
    ]
]
```

## Decision Thresholds

| Probability | Decision | Reasoning |
|-------------|----------|-----------|
| ≥ 60% | Bet = true | High confidence |
| 40-60% | Additional checks | Medium confidence |
| < 20% | Bet = false | Low confidence |

For medium confidence (40-60%), additional filters apply:
- Minimum minute threshold (≥10)
- Sufficient attack intensity
- No red cards (if available)

## Configuration

### Environment Variables

```bash
# Enable/disable algorithm
ALGORITHMX_ENABLED=true

# Sigmoid parameters (for calibration)
ALGORITHMX_SIGMOID_K=2.5
ALGORITHMX_SIGMOID_THRESHOLD=1.8

# Decision thresholds
ALGORITHMX_DECISION_THRESHOLD_HIGH=0.60
ALGORITHMX_DECISION_THRESHOLD_LOW=0.20

# Time constraints
ALGORITHMX_MIN_MINUTE=5
ALGORITHMX_MAX_MINUTE=45
```

### Constants in Config.php

All algorithm parameters are defined in `Config.php`:
- AIS weights
- Score modifiers
- Time factor weights
- Probability bounds
- Decision thresholds

## Usage Example

```php
use Proxbet\Scanner\Algorithms\AlgorithmX\AlgorithmX;
use Proxbet\Scanner\Algorithms\AlgorithmX\Config;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataExtractor;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataValidator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Filters\DecisionFilter;

// Create dependencies
$config = new Config();
$extractor = new DataExtractor();
$validator = new DataValidator();
$calculator = new ProbabilityCalculator(/* ... */);
$filter = new DecisionFilter();

// Create algorithm instance
$algorithm = new AlgorithmX(
    $config,
    $extractor,
    $validator,
    $calculator,
    $filter
);

// Analyze match
$result = $algorithm->analyze([
    'live_data' => $liveData,
]);

// Check result
if ($result['bet']) {
    echo "Bet recommended with {$result['confidence']}% confidence\n";
    echo "Reason: {$result['reason']}\n";
}
```

## Architecture

### Class Structure

```
AlgorithmX/
├── AlgorithmX.php              # Main orchestrator (implements AlgorithmInterface)
├── Config.php                  # Configuration and constants
├── DataExtractor.php           # Extract live data from match record
├── DataValidator.php           # Validate input data
├── Calculators/
│   ├── AisCalculator.php       # Calculate Attack Intensity Score
│   ├── ProbabilityCalculator.php  # Main probability calculation
│   ├── ModifierCalculator.php  # Apply modifiers (time, score, dry period)
│   └── InterpretationGenerator.php  # Generate text interpretation
├── Filters/
│   └── DecisionFilter.php      # Betting decision logic
└── tests/
    └── ...                     # Unit and integration tests
```

### Dependency Injection

All dependencies are injected through constructors following SOLID principles:
- Single Responsibility: Each class has one clear purpose
- Dependency Inversion: Depend on abstractions, not concretions
- Immutability: Use `final` classes and `readonly` properties

## Calibration

The default parameters (`k=2.5`, `threshold=1.8`) are heuristic starting values. For production use, calibrate using historical data:

### Calibration Process

1. **Collect Data**: Gather 500+ matches with live statistics
2. **Label Outcomes**: Mark whether a goal occurred in remaining time
3. **Optimize Parameters**: Use grid search or Bayesian optimization
4. **Validate**: Test on separate validation set
5. **Evaluate Metrics**:
   - **Brier Score** < 0.20 (lower is better)
   - **ROC-AUC** > 0.68 (higher is better)
   - **Calibration Plot**: Predicted vs actual frequencies

### Parameters to Optimize

- Sigmoid `k` and `threshold`
- AIS weights (0.4, 0.3, 0.2, 0.1)
- Score modifiers (1.05, 1.10, 0.90)
- Decision thresholds (0.60, 0.20)

## Limitations

⚠️ **Important Disclaimers:**

1. **Statistical Heuristic**: This is not a machine learning model trained on historical data
2. **Data Quality**: Accuracy depends on live data quality and timeliness
3. **Football Randomness**: Even high probabilities don't guarantee outcomes
4. **No Context**: Doesn't consider team quality, motivation, weather, injuries
5. **First Half Only**: Designed specifically for first half predictions

## Performance Considerations

- **Computation**: O(1) complexity, very fast
- **Memory**: Minimal memory footprint
- **Caching**: Consider caching results for 30-60 seconds per match
- **Database**: Only reads necessary fields from match record

## Testing

Comprehensive test coverage includes:

- **Unit Tests**: Each calculator and filter
- **Integration Tests**: Full algorithm flow
- **Edge Cases**: 
  - minute = 0 (division by zero)
  - minute = 45 (boundary)
  - All statistics = 0
  - Very high statistics values

Run tests:
```bash
vendor/bin/phpunit backend/scanner/Algorithms/AlgorithmX/tests/
```

## Monitoring

Key metrics to track in production:

- Matches analyzed per day
- Probability distribution
- Bet rate (% of matches with bet=true)
- Average probability for bet=true vs bet=false
- Actual outcomes vs predictions (for calibration)

## Future Enhancements

Potential improvements for v2.0:

1. **Machine Learning**: Replace sigmoid with trained model (XGBoost, LightGBM)
2. **Additional Features**:
   - xG (expected goals) if available
   - Possession percentage
   - Team form and motivation
3. **Dynamic Updates**: Recalculate every minute and track trends
4. **Confidence Intervals**: Return probability range, not just point estimate
5. **Multi-target**: Predict number of goals, not just binary outcome

## References

- **Sigmoid Function**: Standard logistic function for probability modeling
- **Attack Intensity**: Weighted combination of offensive statistics
- **Brier Score**: Proper scoring rule for probabilistic predictions
- **ROC-AUC**: Receiver Operating Characteristic - Area Under Curve

## Version History

### v1.0 (2026-03-22)
- Initial implementation
- Basic AIS calculation
- Sigmoid probability model
- Time, score, and dry period modifiers
- Decision filter with thresholds

## Support

For questions or issues:
1. Review this documentation
2. Check `docs/goal_probability_agent_prompt.md` for detailed specification
3. Examine `AlgorithmOne.php` for reference architecture
4. Run PHPStan for static analysis: `vendor/bin/phpstan analyse`

---

**Status**: Phase 1 Complete - Basic structure created  
**Next**: Phase 2 - Implement calculators
