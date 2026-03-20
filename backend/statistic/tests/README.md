# Statistic Module Tests

Unit tests for the `backend/statistic` module.

## Setup

### Requirements

- PHP 8.1+
- PHPUnit 10.5+
- Composer

### Installation

```bash
composer require --dev phpunit/phpunit
```

## Running Tests

### Run all tests

```bash
cd backend/statistic/tests
php vendor/bin/phpunit
```

### Run specific test

```bash
php vendor/bin/phpunit TeamNameNormalizerTest.php
php vendor/bin/phpunit SgiJsonValidatorTest.php
```

### Run with coverage (requires Xdebug)

```bash
php vendor/bin/phpunit --coverage-html coverage/
```

## Test Structure

```
tests/
├── bootstrap.php              # Autoloader for tests
├── phpunit.xml               # PHPUnit configuration
├── TeamNameNormalizerTest.php # Tests for team name normalization
├── SgiJsonValidatorTest.php   # Tests for SGI JSON validation
└── README.md                 # This file
```

## Writing New Tests

1. Create a new test file in `tests/` directory
2. Extend `PHPUnit\Framework\TestCase`
3. Use namespace `Proxbet\Statistic\Tests`
4. Follow naming convention: `{ClassName}Test.php`

Example:

```php
<?php

declare(strict_types=1);

namespace Proxbet\Statistic\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Statistic\YourClass;

final class YourClassTest extends TestCase
{
    public function testSomething(): void
    {
        $instance = new YourClass();
        $result = $instance->doSomething();
        
        $this->assertSame('expected', $result);
    }
}
```

## Test Coverage Goals

- **TeamNameNormalizer**: 100% (critical for match identification)
- **SgiJsonValidator**: 100% (ensures data integrity)
- **HtMetricsCalculator**: 90%+ (complex business logic)
- **TableMetricsCalculator**: 90%+ (complex business logic)
- **StatisticService**: 80%+ (integration logic)

## Mocking

For tests that require database or HTTP calls, use PHPUnit mocks:

```php
$mockRepo = $this->createMock(StatisticRepositoryInterface::class);
$mockRepo->method('listMatchesToUpdate')->willReturn([]);
```

## Continuous Integration

Tests should be run automatically on:
- Every commit (pre-commit hook)
- Every pull request
- Before deployment

## Troubleshooting

### Autoloader issues

If classes are not found, check:
1. `bootstrap.php` has correct paths
2. Namespace matches directory structure
3. File names match class names

### Database tests

For tests requiring database:
1. Use in-memory SQLite for speed
2. Or mock the repository interface
3. Never use production database

## Future Tests to Add

- [ ] `HtMetricsCalculatorTest.php` - Test HT metrics calculation
- [ ] `TableMetricsCalculatorTest.php` - Test table metrics calculation
- [ ] `ConfigTest.php` - Test configuration loading
- [ ] `StatisticServiceTest.php` - Integration tests with mocks
- [ ] `EventsstatClientTest.php` - Test API client with mocked HTTP
