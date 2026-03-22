<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);

$cases = [
    [
        'name' => 'parser',
        'script' => $projectRoot . '/backend/parser.php',
        'env' => [
            'API_URL' => '',
        ],
        'expectedExitCode' => 1,
        'expectedFragments' => ['Missing required env: API_URL'],
    ],
    [
        'name' => 'live',
        'script' => $projectRoot . '/backend/live.php',
        'env' => [
            'DB_HOST' => '',
            'DB_USER' => '',
            'DB_NAME' => '',
        ],
        'expectedExitCode' => 1,
        'expectedFragments' => ['DB_HOST is not set'],
    ],
    [
        'name' => 'stat',
        'script' => $projectRoot . '/backend/stat.php',
        'env' => [
            'DB_HOST' => '',
            'DB_USER' => '',
            'DB_NAME' => '',
        ],
        'expectedExitCode' => 1,
        'expectedFragments' => ['DB_HOST is not set'],
    ],
    [
        'name' => 'scanner',
        'script' => $projectRoot . '/backend/scanner/ScannerCli.php',
        'env' => [
            'DB_HOST' => '',
            'DB_USER' => '',
            'DB_NAME' => '',
        ],
        'expectedExitCode' => 1,
        'expectedFragments' => ['Missing required env: DB_HOST, DB_USER, DB_NAME'],
    ],
    [
        'name' => 'bet_checker',
        'script' => $projectRoot . '/backend/bet_checker.php',
        'env' => [
            'TELEGRAM_BOT_TOKEN' => '',
        ],
        'expectedExitCode' => 1,
        'expectedFragments' => ['Missing required env: TELEGRAM_BOT_TOKEN'],
    ],
];

$failures = [];

foreach ($cases as $case) {
    $result = runSmokeCase($projectRoot, $case);

    if ($result['ok']) {
        fwrite(STDOUT, '[PASS] ' . $case['name'] . PHP_EOL);
        continue;
    }

    fwrite(STDOUT, '[FAIL] ' . $case['name'] . PHP_EOL);
    fwrite(STDOUT, $result['message'] . PHP_EOL);
    $failures[] = $case['name'];
}

if ($failures !== []) {
    fwrite(STDERR, 'Smoke failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Smoke entrypoint checks passed.' . PHP_EOL);
exit(0);

/**
 * @param array{
 *   name:string,
 *   script:string,
 *   env:array<string,string>,
 *   expectedExitCode:int,
 *   expectedFragments:list<string>
 * } $case
 * @return array{ok:bool,message:string}
 */
function runSmokeCase(string $projectRoot, array $case): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $bootstrap = buildInlineBootstrap($case['script'], $case['env']);
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-r', $bootstrap],
        $descriptorSpec,
        $pipes,
        $projectRoot,
        getCurrentEnvironment()
    );

    if (!is_resource($process)) {
        return ['ok' => false, 'message' => 'Failed to start process'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $output = trim((string) $stdout . (string) $stderr);

    if ($exitCode !== $case['expectedExitCode']) {
        return [
            'ok' => false,
            'message' => buildFailureMessage(
                'Unexpected exit code',
                $case['expectedExitCode'],
                $exitCode,
                $output
            ),
        ];
    }

    foreach ($case['expectedFragments'] as $fragment) {
        if (!str_contains($output, $fragment)) {
            return [
                'ok' => false,
                'message' => buildFailureMessage(
                    'Missing expected output fragment',
                    $fragment,
                    $output === '' ? '<empty output>' : $output,
                    $output
                ),
            ];
        }
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * @return array<string,string>
 */
function getCurrentEnvironment(): array
{
    $environment = getenv();

    return is_array($environment) ? $environment : [];
}

/**
 * @param array<string,string> $env
 */
function buildInlineBootstrap(string $scriptPath, array $env): string
{
    $lines = [];

    foreach ($env as $key => $value) {
        $export = var_export($key . '=' . $value, true);
        $keyExport = var_export($key, true);
        $valueExport = var_export($value, true);

        $lines[] = 'putenv(' . $export . ');';
        $lines[] = '$_ENV[' . $keyExport . '] = ' . $valueExport . ';';
        $lines[] = '$_SERVER[' . $keyExport . '] = ' . $valueExport . ';';
    }

    $lines[] = 'require ' . var_export($scriptPath, true) . ';';

    return implode('', $lines);
}

/**
 * @param int|string $expected
 * @param int|string $actual
 */
function buildFailureMessage(string $reason, int|string $expected, int|string $actual, string $output): string
{
    return $reason
        . PHP_EOL . 'Expected: ' . $expected
        . PHP_EOL . 'Actual: ' . $actual
        . PHP_EOL . 'Output:'
        . PHP_EOL . ($output === '' ? '<empty output>' : $output);
}
