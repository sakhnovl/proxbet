<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Services;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

final class LeagueProfileService
{
    /**
     * @return array{
     *   country:string,
     *   liga:string,
     *   normalized_key:string,
     *   category:string,
     *   profile:array{
     *     category:string,
     *     min_attack_tempo:float,
     *     missing_h2h_penalty:float,
     *     xg_weight_multiplier:float,
     *     probability_threshold:float
     *   }
     * }
     */
    public function buildContext(string $country, string $liga): array
    {
        $category = $this->classify($country, $liga);

        return [
            'country' => $country,
            'liga' => $liga,
            'normalized_key' => $this->buildKey($country, $liga),
            'category' => $category,
            'profile' => Config::getLeagueSegmentProfile($category),
        ];
    }

    public function classify(string $country, string $liga): string
    {
        $normalizedLeague = $this->normalize($liga);
        $normalizedKey = $this->buildKey($country, $liga);

        if ($this->matchesWomenLeague($normalizedLeague)) {
            return Config::LEAGUE_CATEGORY_WOMEN;
        }

        if ($this->matchesYouthLeague($normalizedLeague)) {
            return Config::LEAGUE_CATEGORY_YOUTH;
        }

        if (in_array($normalizedKey, $this->topTierKeys(), true)) {
            return Config::LEAGUE_CATEGORY_TOP_TIER;
        }

        return Config::LEAGUE_CATEGORY_LOW_TIER;
    }

    private function matchesWomenLeague(string $normalizedLeague): bool
    {
        foreach ([
            ' women ',
            ' womens ',
            ' woman ',
            ' ladies ',
            ' feminine ',
            ' femenina ',
            ' femenino ',
            ' frauen ',
            ' femminile ',
            ' damallsvenskan ',
        ] as $marker) {
            if (str_contains($normalizedLeague, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function matchesYouthLeague(string $normalizedLeague): bool
    {
        foreach ([
            '/\bu(?:17|18|19|20|21|23)\b/',
            '/\byouth\b/',
            '/\bjunior(?:s)?\b/',
            '/\bjuniores\b/',
            '/\bprimavera\b/',
            '/\breserve(?:s)?\b/',
            '/\bacademy\b/',
            '/\bb team\b/',
            '/\bii\b/',
            '/\biii\b/',
        ] as $pattern) {
            if (preg_match($pattern, $normalizedLeague) === 1) {
                return true;
            }
        }

        return false;
    }

    private function buildKey(string $country, string $liga): string
    {
        return trim($this->normalize($country) . '|' . $this->normalize($liga), '|');
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return ' ' . trim(preg_replace('/\s+/', ' ', $value) ?? $value) . ' ';
    }

    /**
     * @return list<string>
     */
    private function topTierKeys(): array
    {
        return [
            ' england | premier league ',
            ' spain | la liga ',
            ' spain | laliga ',
            ' germany | bundesliga ',
            ' italy | serie a ',
            ' france | ligue 1 ',
            ' netherlands | eredivisie ',
            ' portugal | primeira liga ',
            ' belgium | pro league ',
            ' scotland | premiership ',
            ' switzerland | super league ',
            ' austria | bundesliga ',
            ' denmark | superliga ',
            ' norway | eliteserien ',
            ' sweden | allsvenskan ',
            ' turkey | super lig ',
            ' usa | major league soccer ',
            ' brazil | serie a ',
            ' argentina | liga profesional ',
            ' mexico | liga mx ',
            ' japan | j1 league ',
            ' south korea | k league 1 ',
            ' saudi arabia | saudi pro league ',
        ];
    }
}
