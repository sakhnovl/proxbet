<?php

declare(strict_types=1);

namespace Proxbet\Line;

/**
 * Ban matching logic for filtering matches.
 *
 * Implements rules from docs/todo.md:
 * - normalize strings
 * - tokenize
 * - match by substring OR token intersection (>=3) OR abbreviation (>=3)
 * - ban rule triggers when all non-empty fields match
 */
final class BanMatcher
{
    /** @var array<int,string> */
    private const DEFAULT_STOPWORDS = [
        'fc', 'fk', 'sc', 'cf', 'ac',
        'u19', 'u20', 'u21', 'u23',
        'women', 'w',
        'reserves', 'reserve',
        'ii', 'iii',
    ];

    /**
     * @param array<string,mixed> $banRow expects keys: id,country,liga,home,away,is_active
     * @param array<string,mixed> $match expects keys: country,liga,home,away,evid
     * @return array{matched:bool, fields?:array<int,string>, ban_id?:int}
     */
    public static function matchBan(array $banRow, array $match): array
    {
        if (isset($banRow['is_active']) && (int) $banRow['is_active'] !== 1) {
            return ['matched' => false];
        }

        $fieldsMatched = [];
        $nonNullBanFields = 0;

        foreach (['country', 'liga', 'home', 'away'] as $field) {
            $banVal = self::toNullableString($banRow[$field] ?? null);
            if ($banVal === null) {
                continue;
            }

            $nonNullBanFields++;
            $matchVal = self::toNullableString($match[$field] ?? null) ?? '';
            
            // If match field is empty or doesn't match, this ban doesn't apply
            if ($matchVal === '' || !self::fieldMatches($banVal, $matchVal)) {
                return ['matched' => false];
            }

            $fieldsMatched[] = $field;
        }

        // If rule has no filled fields, treat as non-match (to avoid banning everything by accident)
        if ($nonNullBanFields === 0) {
            return ['matched' => false];
        }

        // All non-null ban fields matched
        return [
            'matched' => true,
            'fields' => $fieldsMatched,
            'ban_id' => (int) ($banRow['id'] ?? 0),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $bans
     * @param array<string,mixed> $match
     * @return array{matched:bool, ban?:array<string,mixed>, fields?:array<int,string>}
     */
    public static function matchAny(array $bans, array $match): array
    {
        foreach ($bans as $ban) {
            $res = self::matchBan($ban, $match);
            if ($res['matched']) {
                return ['matched' => true, 'ban' => $ban, 'fields' => $res['fields'] ?? []];
            }
        }

        return ['matched' => false];
    }

    public static function fieldMatches(string $banValue, string $matchValue): bool
    {
        $banNorm = self::normalize($banValue);
        $matchNorm = self::normalize($matchValue);

        if ($banNorm === '' || $matchNorm === '') {
            return false;
        }

        if ($banNorm === $matchNorm) {
            return true;
        }

        return str_contains($matchNorm, $banNorm) || str_contains($banNorm, $matchNorm);
    }

    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace('ё', 'е', $s);

        // normalize whitespace (including NBSP)
        $s = str_replace(["\u{00A0}", "\t", "\n", "\r"], ' ', $s);

        // strip accents/diacritics when intl is available (helps: "São" vs "Sao")
        if (class_exists(\Normalizer::class)) {
            $norm = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($norm)) {
                $s = $norm;
                $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
            }
        } else {
            // Fallback: manual transliteration for common accented characters
            $s = self::removeAccents($s);
        }

        // keep common apostrophes as "joiners" (helps: "O'Higgins" vs "OHiggins")
        $s = str_replace(["'", "'", "'", '`', '´'], '', $s);

        // replace punctuation/separators with spaces (keep letters+numbers)
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;

        // collapse whitespace
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);

        if ($s === '') {
            return '';
        }

        // remove stopwords
        $tokens = self::tokensRaw($s);
        if ($tokens === []) {
            return '';
        }

        $stop = array_flip(self::DEFAULT_STOPWORDS);
        $filtered = [];
        foreach ($tokens as $t) {
            if ($t === '' || isset($stop[$t])) {
                continue;
            }
            $filtered[] = $t;
        }

        return trim(implode(' ', $filtered));
    }

    private static function removeAccents(string $s): string
    {
        $replacements = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ñ' => 'n', 'ç' => 'c'
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $s);
    }

    /** @return array<int,string> */
    private static function tokensRaw(string $s): array
    {
        $parts = preg_split('/\s+/u', trim($s)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    private static function toNullableString(mixed $v): ?string
    {
        if ($v === null || !is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
