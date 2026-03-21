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
        }

        return;
    }
});
