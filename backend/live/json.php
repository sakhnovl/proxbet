<?php

declare(strict_types=1);

namespace Proxbet\Live;

/**
 * JSON traversal helpers.
 *
 * Live API may contain array-like objects where keys are strings: "0", "1", ...
 * We treat both associative arrays and such structures uniformly.
 */
final class Json
{
    /**
     * Safe nested access by path.
     *
     * @param array<string,mixed> $root
     * @param array<int,string|int> $path
     */
    public static function get(array $root, array $path, mixed $default = null): mixed
    {
        $cur = $root;
        foreach ($path as $key) {
            if (!is_array($cur)) {
                return $default;
            }

            $k = is_int($key) ? (string) $key : $key;
            if (!array_key_exists($k, $cur)) {
                return $default;
            }
            $cur = $cur[$k];
        }

        return $cur;
    }

    /**
     * Returns list of child arrays under $node regardless of numeric string keys.
     *
     * @param mixed $node
     * @return array<int,array<string,mixed>>
     */
    public static function children(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $out = [];
        foreach ($node as $v) {
            if (is_array($v)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    public static function intOrZero(mixed $v): int
    {
        if ($v === null) {
            return 0;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }

    public static function floatOrNull(mixed $v): ?float
    {
        if ($v === null) {
            return null;
        }
        if (is_float($v) || is_int($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }

        return null;
    }
}
