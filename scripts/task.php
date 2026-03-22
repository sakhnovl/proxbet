<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

$command = $argv[1] ?? 'help';

$commands = [
    'help' => 'Показать список доступных команд',
    'install' => 'Установить Composer зависимости',
    'up' => 'Поднять базовый Docker stack',
    'up:cache' => 'Поднять stack с optional Redis profile',
    'up:dev' => 'Поднять stack с dev profile',
    'up:observability' => 'Поднять stack с observability profile',
    'down' => 'Остановить Docker stack',
    'test' => 'Запустить fast regression набор',
    'test:line' => 'Запустить line PHPUnit suite',
    'test:scanner' => 'Запустить scanner PHPUnit suite',
    'test:statistic' => 'Запустить statistic PHPUnit suite',
    'test:telegram' => 'Запустить telegram PHPUnit suite',
    'test:smoke' => 'Запустить smoke suite',
    'test:security' => 'Запустить security regression suite',
    'test:e2e' => 'Запустить e2e suite',
    'test:regression' => 'Запустить security + e2e suites',
    'phpstan' => 'Запустить PHPStan',
    'validate' => 'Запустить fast regression набор и PHPStan',
];

if (!array_key_exists($command, $commands)) {
    fwrite(STDERR, "Unknown command: {$command}\n\n");
    printHelp($commands);
    exit(1);
}

$exitCode = match ($command) {
    'help' => printHelp($commands),
    'install' => runShell('composer install'),
    'up' => runShell('docker compose up -d --build'),
    'up:cache' => runShell('docker compose --profile cache up -d --build'),
    'up:dev' => runShell('docker compose --profile dev up -d --build'),
    'up:observability' => runShell('docker compose --profile observability up -d --build'),
    'down' => runShell('docker compose down'),
    'test' => runSequence([
        static fn (): int => runPhp(['vendor/bin/phpunit', '-c', 'backend/line/tests/phpunit.xml']),
        static fn (): int => runPhp(['vendor/bin/phpunit', '-c', 'backend/scanner/tests/phpunit.xml']),
        static fn (): int => runPhp(['vendor/bin/phpunit', '-c', 'backend/statistic/tests/phpunit.xml']),
        static fn (): int => runPhp(['vendor/bin/phpunit', '-c', 'backend/telegram/tests/phpunit.xml']),
        static fn (): int => runPhp(['scripts/smoke/cli_entrypoints.php']),
    ]),
    'test:line' => runPhp(['vendor/bin/phpunit', '-c', 'backend/line/tests/phpunit.xml']),
    'test:scanner' => runPhp(['vendor/bin/phpunit', '-c', 'backend/scanner/tests/phpunit.xml']),
    'test:statistic' => runPhp(['vendor/bin/phpunit', '-c', 'backend/statistic/tests/phpunit.xml']),
    'test:telegram' => runPhp(['vendor/bin/phpunit', '-c', 'backend/telegram/tests/phpunit.xml']),
    'test:smoke' => runPhp(['scripts/smoke/cli_entrypoints.php']),
    'test:security' => runPhp(
        ['vendor/bin/phpunit', '-c', 'tests/security/phpunit.xml'],
        ['RUN_SECURITY_TESTS' => '1']
    ),
    'test:e2e' => runPhp(
        ['vendor/bin/phpunit', '-c', 'tests/e2e/phpunit.xml'],
        ['RUN_E2E_TESTS' => '1']
    ),
    'test:regression' => runSequence([
        static fn (): int => runPhp(
            ['vendor/bin/phpunit', '-c', 'tests/security/phpunit.xml'],
            ['RUN_SECURITY_TESTS' => '1']
        ),
        static fn (): int => runPhp(
            ['vendor/bin/phpunit', '-c', 'tests/e2e/phpunit.xml'],
            ['RUN_E2E_TESTS' => '1']
        ),
    ]),
    'phpstan' => runPhp(['vendor/bin/phpstan', 'analyse', '--no-progress']),
    'validate' => runSequence([
        static fn (): int => runPhp(['vendor/bin/phpunit', '-c', 'backend/line/tests/phpunit.xml']),
        static fn (): int => runPhp(['vendor/bin/phpunit', '-c', 'backend/scanner/tests/phpunit.xml']),
        static fn (): int => runPhp(['vendor/bin/phpunit', '-c', 'backend/statistic/tests/phpunit.xml']),
        static fn (): int => runPhp(['vendor/bin/phpunit', '-c', 'backend/telegram/tests/phpunit.xml']),
        static fn (): int => runPhp(['scripts/smoke/cli_entrypoints.php']),
        static fn (): int => runPhp(['vendor/bin/phpstan', 'analyse', '--no-progress']),
    ]),
};

exit($exitCode);

/**
 * @param array<string,string> $commands
 */
function printHelp(array $commands): int
{
    fwrite(STDOUT, "Usage: php scripts/task.php <command>\n\n");
    fwrite(STDOUT, "Available commands:\n");

    foreach ($commands as $name => $description) {
        fwrite(STDOUT, sprintf("  %-18s %s\n", $name, $description));
    }

    return 0;
}

/**
 * @param list<callable(): int> $steps
 */
function runSequence(array $steps): int
{
    foreach ($steps as $step) {
        $exitCode = $step();
        if ($exitCode !== 0) {
            return $exitCode;
        }
    }

    return 0;
}

/**
 * @param list<string> $arguments
 * @param array<string,string> $env
 */
function runPhp(array $arguments, array $env = []): int
{
    $command = escapeshellarg(PHP_BINARY);

    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    return runShell($command, $env);
}

/**
 * @param array<string,string> $env
 */
function runShell(string $command, array $env = []): int
{
    $previousValues = [];

    foreach ($env as $key => $value) {
        $previous = getenv($key);
        $previousValues[$key] = $previous === false ? null : $previous;
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    passthru($command, $exitCode);

    foreach ($previousValues as $key => $previousValue) {
        if ($previousValue === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            continue;
        }

        putenv($key . '=' . $previousValue);
        $_ENV[$key] = $previousValue;
        $_SERVER[$key] = $previousValue;
    }

    return $exitCode;
}
