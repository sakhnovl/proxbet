<?php

declare(strict_types=1);

namespace Proxbet\Line;

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
    private static function log(string $level, string $message, array $context): void
    {
        self::init();

        $ts = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
        $line = sprintf('[%s] %s: %s', $ts, $level, $message);

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }

        $line .= "\n";
        fwrite(self::$stream, $line);
    }
}
