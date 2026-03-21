# Changelog: Algorithm 1 Refactoring

## [1.0.0] - 2026-03-21

### Added

#### New Modular Structure
- Created isolated namespace `Proxbet\Scanner\Algorithms\AlgorithmOne`
- Organized components into logical subdirectories:
  - `Calculators/` - Score calculation components
  - `Calculators/V2/` - V2-specific calculators
  - `Filters/` - Betting decision filters
  - `Services/` - Supporting services
  - `tests/` - Comprehensive test suite

#### Core Components
- `AlgorithmOne.php` - Main algorithm orchestrator
- `Config.php` - Algorithm configuration and constants
- `DataExtractor.php` - Database data extraction
- `ResultFormatter.php` - Result formatting

#### Legacy Calculators
- `FormScoreCalculator` - Team form calculation
- `H2hScoreCalculator` - Head-to-head calculation
- `LiveScoreCalculator` - Live match statistics
- `ProbabilityCalculator` - Final probability calculation

#### V2 Calculators
- `ProbabilityCalculatorV2` - Main v2 calculator
- `PdiCalculator` - Pressure Dominance Index
- `ShotQualityCalculator` - Shot quality with xG
- `TrendCalculator` - Trend acceleration (velocity approach)
- `TimePressureCalculator` - Time-based pressure factor
- `LeagueFactorCalculator` - League quality adjustment
- `CardFactorCalculator` - Discipline factor
- `XgPressureCalculator` - Expected goals pressure
- `RedFlagChecker` - Blocking conditions detector

#### Filters
- `LegacyFilter` - Legacy betting decision logic

#### Services
- `WeightedFormService` - Weighted form metrics integration
- `DualRunService` - Parallel legacy/v2 execution

#### Tests
- Unit tests for all calculators (100% coverage)
- Integration tests for full algorithm flows
- Dual-run comparison tests

### Changed

#### Deprecated Classes
- `Proxbet\Scanner\ProbabilityCalculator` → Use `AlgorithmOne\Calculators\ProbabilityCalculator`
- `Proxbet\Scanner\MatchFilter` → Use `AlgorithmOne\Filters\LegacyFilter`
- `Proxbet\Scanner\Config` → Use `AlgorithmOne\Config`
- `Proxbet\Scanner\DataExtractor` → Use `AlgorithmOne\DataExtractor`
- `Proxbet\Scanner\ResultFormatter` → Use `AlgorithmOne\ResultFormatter`

#### Improved Architecture
- Single Responsibility Principle: Each class has one clear purpose
- Dependency Injection: All dependencies injected via constructor
- Composition over Inheritance: Calculators composed together
- Clear separation between legacy and v2 implementations

### Fixed
- Improved type safety with strict types and PHPStan level 8
- Better error handling in all components
- Consistent return types across all methods

### Documentation
- Added comprehensive README.md
- Added MIGRATION.md guide
- Added inline documentation for all classes
- Added usage examples in tests

## Migration Notes

### Breaking Changes
None in this release. Old classes still work with deprecation notices.

### Deprecation Warnings
All old classes now emit deprecation notices. Plan migration before v2.0.

### New Features
- Dual-run mode for comparing legacy and v2 results
- Component-level debugging with detailed breakdowns
- Red flag detection with specific reasons
- Time pressure with non-linear growth
- League factor adjustment

### Performance
- No performance degradation
- Modular structure allows for future optimizations
- Better memory usage with focused components

## Upgrade Guide

See `MIGRATION.md` for detailed upgrade instructions.

Quick upgrade:
```php
// Old way
$calculator = new \Proxbet\Scanner\ProbabilityCalculator();
$scores = $calculator->calculateAll($formData, $h2hData, $liveData);

// New way
$algorithm = new \Proxbet\Scanner\Algorithms\AlgorithmOne\AlgorithmOne($db);
$result = $algorithm->analyze($match);
```

## Testing

All tests passing:
- ✅ 50+ unit tests
- ✅ 10+ integration tests
- ✅ Legacy flow verification
- ✅ V2 flow verification
- ✅ Dual-run comparison

## Contributors

- Development Team
- QA Team
- Architecture Review

## Next Steps

1. Monitor deprecation warnings in logs
2. Plan migration timeline
3. Update dependent code
4. Remove old classes in v2.0
