# Migration Guide: Algorithm 1 Refactoring

## Overview

Algorithm 1 has been refactored into a modular, isolated structure under `backend/scanner/Algorithms/AlgorithmOne/`. The old classes in `backend/scanner/` are now deprecated and will be removed in a future version.

## Deprecated Classes

The following classes are deprecated:

| Old Class | New Class | Status |
|-----------|-----------|--------|
| `Proxbet\Scanner\ProbabilityCalculator` | `Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator` (legacy)<br>`Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ProbabilityCalculatorV2` (v2) | ⚠️ Deprecated |
| `Proxbet\Scanner\MatchFilter` | `Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter` (Algorithm 1 only) | ⚠️ Deprecated |
| `Proxbet\Scanner\Config` | `Proxbet\Scanner\Algorithms\AlgorithmOne\Config` | ⚠️ Deprecated |
| `Proxbet\Scanner\DataExtractor` | `Proxbet\Scanner\Algorithms\AlgorithmOne\DataExtractor` | ⚠️ Deprecated |
| `Proxbet\Scanner\ResultFormatter` | `Proxbet\Scanner\Algorithms\AlgorithmOne\ResultFormatter` | ⚠️ Deprecated |

## Migration Timeline

- **Current**: Old classes still work with deprecation notices
- **Next Release (v2.0)**: Old classes will be removed
- **Recommended Action**: Migrate to new classes as soon as possible

## How to Migrate

### Option 1: Use AlgorithmOne Directly (Recommended)

Instead of manually calling individual calculators and filters, use the `AlgorithmOne` class:

**Before:**
```php
use Proxbet\Scanner\ProbabilityCalculator;
use Proxbet\Scanner\MatchFilter;
use Proxbet\Scanner\DataExtractor;

$calculator = new ProbabilityCalculator();
$calculator->setAlgorithmVersion(2);
$scores = $calculator->calculateAll($formData, $h2hData, $liveData);

$filter = new MatchFilter();
$decision = $filter->shouldBetAlgorithmOne($liveData, $scores['probability'], $formData, $h2hData);
```

**After:**
```php
use Proxbet\Scanner\Algorithms\AlgorithmOne\AlgorithmOne;

$algorithm = new AlgorithmOne($db);
$result = $algorithm->analyze($match);

// Result contains everything: scores, decision, components, etc.
$probability = $result['probability'];
$decision = $result['decision'];
$components = $result['components'] ?? null; // v2 only
```

### Option 2: Use Individual Components

If you need fine-grained control, use the new modular components:

**Legacy Calculation:**
```php
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\FormScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\H2hScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\LiveScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator;

$formCalc = new FormScoreCalculator();
$h2hCalc = new H2hScoreCalculator();
$liveCalc = new LiveScoreCalculator();
$probCalc = new ProbabilityCalculator($formCalc, $h2hCalc, $liveCalc);

$probability = $probCalc->calculate($formData, $h2hData, $liveData);
```

**V2 Calculation:**
```php
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ProbabilityCalculatorV2;
// ... other V2 calculators

$v2Calc = new ProbabilityCalculatorV2(
    $formCalc,
    $h2hCalc,
    $pdiCalc,
    $shotQualityCalc,
    // ... other calculators
);

$result = $v2Calc->calculate($formData, $h2hData, $liveData);
```

### Option 3: Use Filters

**Before:**
```php
use Proxbet\Scanner\MatchFilter;

$filter = new MatchFilter();
$decision = $filter->shouldBetAlgorithmOne($liveData, $probability, $formData, $h2hData);
```

**After:**
```php
use Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter;

$filter = new LegacyFilter();
$decision = $filter->shouldBet($liveData, $probability, $formData, $h2hData);
```

## Configuration Changes

**Before:**
```php
use Proxbet\Scanner\Config;

$version = Config::getAlgorithmVersion();
$dualRun = Config::isDualRunEnabled();
```

**After:**
```php
use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

$version = Config::getAlgorithmVersion();
$dualRun = Config::isDualRunEnabled();

// New constants available:
$minMinute = Config::MIN_MINUTE; // 15
$maxMinute = Config::MAX_MINUTE; // 30
$minProbability = Config::MIN_PROBABILITY; // 0.55
```

## Data Extraction Changes

**Before:**
```php
use Proxbet\Scanner\DataExtractor;

$extractor = new DataExtractor($db);
$formData = $extractor->extractFormData($match);
$liveData = $extractor->extractLiveData($match);
```

**After:**
```php
use Proxbet\Scanner\Algorithms\AlgorithmOne\DataExtractor;

$extractor = new DataExtractor($db);
$formData = $extractor->extractFormData($match);
$liveData = $extractor->extractLiveData($match);

// For v2 with weighted metrics:
$formDataV2 = $extractor->extractFormDataV2($match, $weightedMetrics);
$liveDataV2 = $extractor->extractLiveDataV2($match);
```

## Result Formatting Changes

**Before:**
```php
use Proxbet\Scanner\ResultFormatter;

$formatter = new ResultFormatter();
$result = $formatter->formatAlgorithmOne($base, $liveData, $scores, $formData, $h2hData, $decision);
```

**After:**
```php
use Proxbet\Scanner\Algorithms\AlgorithmOne\ResultFormatter;

$formatter = new ResultFormatter();
$result = $formatter->format($base, $liveData, $scores, $formData, $h2hData, $decision);
```

## Environment Variables

No changes to environment variables:

```env
# Algorithm version (1 = legacy, 2 = v2)
ALGORITHM_VERSION=2

# Dual-run mode (calculate both versions for comparison)
ALGORITHM1_DUAL_RUN=1
```

## Testing

All old functionality is preserved. Run existing tests to verify:

```bash
# Run all scanner tests
php vendor/bin/phpunit backend/scanner/tests/

# Run Algorithm 1 specific tests
php vendor/bin/phpunit backend/scanner/Algorithms/AlgorithmOne/tests/
```

## Benefits of Migration

1. **Modularity**: Each component has a single responsibility
2. **Testability**: Every component has comprehensive unit tests
3. **Maintainability**: Clear separation between legacy and v2
4. **Extensibility**: Easy to add new components or modify existing ones
5. **Type Safety**: Better type hints and PHPStan compliance
6. **Documentation**: Each component is well-documented

## Support

If you encounter issues during migration:

1. Check the deprecation notices in old classes
2. Review the new class documentation
3. Look at integration tests for usage examples
4. Consult `backend/scanner/Algorithms/AlgorithmOne/README.md`

## Rollback Plan

If you need to rollback temporarily:

1. Old classes still work (with deprecation notices)
2. No breaking changes in current version
3. Simply continue using old classes until migration is complete

## Timeline

- **Now**: Start migration, old classes work with warnings
- **v1.9**: Final warning, old classes marked for removal
- **v2.0**: Old classes removed, migration required

Migrate early to avoid disruption!
