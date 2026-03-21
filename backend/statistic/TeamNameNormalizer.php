<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

final class TeamNameNormalizer
{
    /**
     * @var array<string,string>
     */
    private const ALIASES = [
        'man utd' => 'manchester united',
        'man united' => 'manchester united',
        'man city' => 'manchester city',
        'psg' => 'paris saint germain',
        'internazionale' => 'inter',
    ];

    public static function normalize(string $name): string
    {
        $value = trim(mb_strtolower($name, 'UTF-8'));
        if ($value === '') {
            return '';
        }

        $value = str_replace(["\u{00A0}", 'ё'], [' ', 'е'], $value);
        $value = preg_replace("/[\"'`’‘‛′.,()\\[\\]{}]+/u", '', $value) ?? $value;
        $value = preg_replace('/[\p{Pd}\x{2212}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return self::ALIASES[$value] ?? $value;
    }

    public static function equals(string $left, string $right): bool
    {
        $normLeft = self::normalize($left);
        $normRight = self::normalize($right);

        return $normLeft !== '' && $normLeft === $normRight;
    }
}
