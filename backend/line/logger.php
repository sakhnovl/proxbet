<?php

declare(strict_types=1);

namespace Proxbet\Line;

require_once __DIR__ . '/../security/LogFilter.php';

use Proxbet\Security\LogFilter;

final class Logger
{
    /** @var resource|null */
    private static $stream = null;

    public static function init(): void
    {
        if (self::$stream !== null) {
            return;
        }

        self::$stream = fopen('php://stdout', 'wb');
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private static function log(string $level, string $message, array $context): void
    {
        self::init();

        // Filter sensitive data before logging
        $filteredMessage = LogFilter::filter($message);
        $filteredContext = LogFilter::filterArray($context);

        $ts = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
        $line = sprintf('[%s] %s: %s', $ts, $level, $filteredMessage);

        if ($filteredContext !== []) {
            $json = json_encode($filteredContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }

        $line .= "\n";
        fwrite(self::$stream, $line);
    }
}
