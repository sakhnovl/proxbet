# Decision Filters for AlgorithmX

## Overview

This directory contains decision-making filters that determine whether to place a bet based on calculated probability and match conditions.

## DecisionFilter.php

Main filter that implements betting decision logic.

### Decision Thresholds

- **High Probability (≥60%)**: Strong bet signal
- **Medium Probability (20-60%)**: Requires additional analysis
- **Low Probability (<20%)**: No bet

### Additional Filtering Conditions

1. **Minimum Minute**: At least 10 minutes of match data required
2. **Match Activity**: AIS rate must be ≥ 0.8 (active match)
3. **Score Difference**: Maximum 2 goals difference (avoid blowouts)
4. **Dangerous Attacks**: Minimum 5 total dangerous attacks
5. **Time Remaining**: At least 5 minutes left in first half
6. **Shot Quality**: Shots on target as quality indicator

### Usage Example

```php
use Proxbet\Scanner\Algorithms\AlgorithmX\Filters\DecisionFilter;

$filter = new DecisionFilter();

$decision = $filter->shouldBet(
    probability: 0.52,  // 52%
    liveData: [
        'minute' => 28,
        'score_home' => 0,
        'score_away' => 0,
        'dangerous_attacks_home' => 14,
        'dangerous_attacks_away' => 10,
        'shots_on_target_home' => 4,
        'shots_on_target_away' => 2,
    ],
    debug: ['ais_rate' => 1.5]
);

// Result:
// [
//     'bet' => true,
//     'reason' => 'Medium-high probability (52.0%) with good shot quality (6 on target). Cautious bet recommended.'
// ]
```

### Decision Logic Flow

```
Input: probability, liveData, debug
    ↓
Is probability ≥ 60%?
    YES → Check blowout & data sufficiency → BET or NO BET
    NO  ↓
Is probability < 20%?
    YES → NO BET (low activity)
    NO  ↓
Medium probability (20-60%)
    ↓
Check 6 conditions:
    1. Minute ≥ 10?
    2. AIS rate ≥ 0.8?
    3. Score diff ≤ 2?
    4. Dangerous attacks ≥ 5?
    5. Time remaining ≥ 5?
    6. Shot quality good?
    ↓
If probability ≥ 40% AND shots on target ≥ 3
    → BET (cautious)
Else
    → NO BET (conservative)
```

## Testing

Comprehensive test suite available in `tests/Filters/DecisionFilterTest.php`:

- 18 test cases
- All probability ranges covered
- Boundary conditions tested
- Edge cases handled
- Realistic match scenarios

Run tests:
```bash
vendor/bin/phpunit backend/scanner/Algorithms/AlgorithmX/tests/Filters/DecisionFilterTest.php
```

## Configuration

Thresholds can be adjusted in `Config.php`:

```php
const DECISION_THRESHOLD_HIGH = 0.60;    // 60%
const DECISION_THRESHOLD_MEDIUM = 0.40;  // 40%
const DECISION_THRESHOLD_LOW = 0.20;     // 20%
```

Or via environment variables:
```bash
ALGORITHMX_DECISION_THRESHOLD_HIGH=0.60
ALGORITHMX_DECISION_THRESHOLD_LOW=0.20
```
