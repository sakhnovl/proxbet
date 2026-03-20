<?php

declare(strict_types=1);

namespace Proxbet\Line;

final class Normalize
{
    /** @param array<string,mixed> $arr */
    public static function getString(array $arr, string $key): ?string
    {
        if (!array_key_exists($key, $arr) || $arr[$key] === null) {
            return null;
        }

        $v = $arr[$key];
        if (is_string($v)) {
            $v = trim($v);
            return $v === '' ? null : $v;
        }

        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return null;
    }

    /** @param array<string,mixed> $arr */
    public static function getInt(array $arr, string $key): ?int
    {
        if (!array_key_exists($key, $arr) || $arr[$key] === null) {
            return null;
        }

        $v = $arr[$key];

        if (is_int($v)) {
            return $v;
        }

        if (is_float($v)) {
            return (int) $v;
        }

        if (is_string($v)) {
            $v = trim($v);
            if ($v === '' || !preg_match('/^-?\d+$/', $v)) {
                return null;
            }

            return (int) $v;
        }

        return null;
    }

    /** @param array<string,mixed> $arr */
    public static function getDecimal(array $arr, string $key): ?float
    {
        if (!array_key_exists($key, $arr) || $arr[$key] === null) {
            return null;
        }

        $v = $arr[$key];
        if (is_int($v) || is_float($v)) {
            return round((float) $v, 2);
        }

        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
            if ($v === '' || !is_numeric($v)) {
                return null;
            }

            return round((float) $v, 2);
        }

        return null;
    }

    /** @param array<string,mixed> $arr */
    public static function getArray(array $arr, string $key): array
    {
        if (!array_key_exists($key, $arr) || !is_array($arr[$key])) {
            return [];
        }

        return $arr[$key];
    }
}
