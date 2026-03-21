<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__, 2);
$vendorAutoload = $rootDir . '/vendor/autoload.php';

if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

spl_autoload_register(static function (string $class) use ($rootDir): void {
    static $prefixes = [
        'Psr\\Log\\' => '/backend/support/Psr/Log/',
        'Proxbet\\Core\\' => '/backend/core/',
        'Proxbet\\Line\\' => '/backend/line/',
        'Proxbet\\Live\\' => '/backend/live/',
        'Proxbet\\Scanner\\' => '/backend/scanner/',
        'Proxbet\\Statistic\\' => '/backend/statistic/',
        'Proxbet\\Telegram\\' => '/backend/telegram/',
    ];

    foreach ($prefixes as $prefix => $relativeBaseDir) {
        $prefixLength = strlen($prefix);
        if (strncmp($class, $prefix, $prefixLength) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $prefixLength);
        $path = $rootDir . $relativeBaseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($path)) {
            require_once $path;
            return;
        }

        $segments = explode('/', trim($relativeBaseDir . str_replace('\\', '/', $relativeClass) . '.php', '/'));
        $resolvedPath = $rootDir;

        foreach ($segments as $segment) {
            $candidatePath = $resolvedPath . '/' . $segment;
            if (is_file($candidatePath) || is_dir($candidatePath)) {
                $resolvedPath = $candidatePath;
                continue;
            }

            $matches = glob($resolvedPath . '/' . $segment, GLOB_NOSORT)
                ?: glob($resolvedPath . '/*', GLOB_NOSORT);

            $matchedPath = null;
            foreach ($matches as $match) {
                if (strcasecmp(basename($match), $segment) === 0) {
                    $matchedPath = $match;
                    break;
                }
            }

            if ($matchedPath === null) {
                return;
            }

            $resolvedPath = $matchedPath;
        }

        if (is_file($resolvedPath)) {
            require_once $resolvedPath;
        }

        return;
    }
});
