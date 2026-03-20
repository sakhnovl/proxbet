<?php

declare(strict_types=1);

namespace Proxbet\Line;

final class Env
{
    /**
     * Minimal .env loader for CLI runs.
     * - Supports KEY=VALUE
     * - Ignores empty lines and comments (# ...)
     * - Does not expand variables
     */
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // Strip optional surrounding quotes
            if ($val !== '' && ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'")))) {
                $val = substr($val, 1, -1);
            }

            if ($key === '') {
                continue;
            }

            // Do not overwrite already-set env
            if (getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}
