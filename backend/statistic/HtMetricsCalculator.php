<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

use Proxbet\Statistic\Interfaces\MetricsCalculatorInterface;

/**
 * Calculator for Half-Time (HT) metrics based on historical match data.
 *
 * Calculates statistics for:
 * - Last 5 matches (Q data)
 * - Head-to-head last 5 matches (G data)
 */
final class HtMetricsCalculator implements MetricsCalculatorInterface
{
    /**
     * Calculate all HT metrics for a match.
     *
     * @param array<string,mixed> $sgi SGI JSON data
     * @param string $home Home team name
     * @param string $away Away team name
     * @return array{metrics: array<string,int|float|null>, debug: array<string,mixed>}
     */
    public function calculate(array $sgi, string $home, string $away): array
    {
        $homeNorm = TeamNameNormalizer::normalize($home);
        $awayNorm = TeamNameNormalizer::normalize($away);
        $lists = $this->extractMatchLists($sgi);

        $homeLast = $lists['home_last5'] === [] ? null : $this->calculateForTeam($lists['home_last5'], $homeNorm);
        $awayLast = $lists['away_last5'] === [] ? null : $this->calculateForTeam($lists['away_last5'], $awayNorm);
        $homeH2h = $lists['h2h5'] === [] ? null : $this->calculateForTeam($lists['h2h5'], $homeNorm);
        $awayH2h = $lists['h2h5'] === [] ? null : $this->calculateForTeam($lists['h2h5'], $awayNorm);

        // Calculate weighted form for Algorithm 1 v2
        $homeWeighted = $lists['home_last5'] === [] ? null : $this->calculateWeightedForm($lists['home_last5'], $homeNorm);
        $awayWeighted = $lists['away_last5'] === [] ? null : $this->calculateWeightedForm($lists['away_last5'], $awayNorm);
        $weightedScore = $this->calculateWeightedFormScore($homeWeighted, $awayWeighted);

        $emptyDebug = ['considered' => 0, 'skipped' => 0];

        return [
            'metrics' => [
                // last5 (Q) - home
                'ht_match_goals_1' => $homeLast['match_goals'] ?? null,
                'ht_match_missed_goals_1' => $homeLast['match_missed_goals'] ?? null,
                'ht_match_goals_1_avg' => $homeLast['goals_avg'] ?? null,
                'ht_match_missed_1_avg' => $homeLast['missed_avg'] ?? null,
                // last5 (Q) - away
                'ht_match_goals_2' => $awayLast['match_goals'] ?? null,
                'ht_match_missed_goals_2' => $awayLast['match_missed_goals'] ?? null,
                'ht_match_goals_2_avg' => $awayLast['goals_avg'] ?? null,
                'ht_match_missed_2_avg' => $awayLast['missed_avg'] ?? null,
                // h2h5 (G) - home
                'h2h_ht_match_goals_1' => $homeH2h['match_goals'] ?? null,
                'h2h_ht_match_missed_goals_1' => $homeH2h['match_missed_goals'] ?? null,
                'h2h_ht_match_goals_1_avg' => $homeH2h['goals_avg'] ?? null,
                'h2h_ht_match_missed_1_avg' => $homeH2h['missed_avg'] ?? null,
                // h2h5 (G) - away
                'h2h_ht_match_goals_2' => $awayH2h['match_goals'] ?? null,
                'h2h_ht_match_missed_goals_2' => $awayH2h['match_missed_goals'] ?? null,
                'h2h_ht_match_goals_2_avg' => $awayH2h['goals_avg'] ?? null,
                'h2h_ht_match_missed_2_avg' => $awayH2h['missed_avg'] ?? null,
            ],
            'debug' => [
                'home_last5' => $homeLast === null ? $emptyDebug : [
                    'considered' => $homeLast['considered'],
                    'skipped' => $homeLast['skipped'],
                ],
                'away_last5' => $awayLast === null ? $emptyDebug : [
                    'considered' => $awayLast['considered'],
                    'skipped' => $awayLast['skipped'],
                ],
                'home_h2h5' => $homeH2h === null ? $emptyDebug : [
                    'considered' => $homeH2h['considered'],
                    'skipped' => $homeH2h['skipped'],
                ],
                'away_h2h5' => $awayH2h === null ? $emptyDebug : [
                    'considered' => $awayH2h['considered'],
                    'skipped' => $awayH2h['skipped'],
                ],
                'match_context' => [
                    'home_input' => $home,
                    'away_input' => $away,
                    'home_normalized' => $homeNorm,
                    'away_normalized' => $awayNorm,
                ],
                'algorithm1_v2' => [
                    'form' => [
                        'home' => [
                            'attack' => $homeWeighted['attack'] ?? null,
                            'defense' => $homeWeighted['defense'] ?? null,
                        ],
                        'away' => [
                            'attack' => $awayWeighted['attack'] ?? null,
                            'defense' => $awayWeighted['defense'] ?? null,
                        ],
                        'weighted_score' => $weightedScore,
                    ],
                ],
            ],
        ];
    }

    /**
     * Extract match lists from SGI data.
     *
     * @return array{home_last5: array<int,mixed>, away_last5: array<int,mixed>, h2h5: array<int,mixed>}
     */
    private function extractMatchLists(array $sgi): array
    {
        $homeLast = [];
        $awayLast = [];
        $h2h = [];

        $q = $sgi['Q'] ?? null;
        if (is_array($q)) {
            $hasSplitLists = array_key_exists('H', $q) && array_key_exists('A', $q)
                && (is_array($q['H']) || is_array($q['A']));

            if ($hasSplitLists) {
                $homeLast = is_array($q['H']) ? $this->getFirstN($q['H'], 5) : [];
                $awayLast = is_array($q['A']) ? $this->getFirstN($q['A'], 5) : [];
                $h2h = (isset($q['G']) && is_array($q['G'])) ? $this->getFirstN($q['G'], 5) : [];
            } else {
                $homeLast = $this->getFirstN($q, 5);
                $awayLast = $this->getFirstN($q, 5);
            }
        }

        // Prefer top-level G for h2h if present
        $g = $sgi['G'] ?? null;
        if (is_array($g)) {
            $h2h = $this->getFirstN($g, 5);
        }

        return ['home_last5' => $homeLast, 'away_last5' => $awayLast, 'h2h5' => $h2h];
    }

    /**
     * Calculate metrics for a specific team across multiple matches.
     *
     * IMPORTANT: match_goals represents the COUNT of matches where the team scored at least 1 goal
     * in the first half (range: 0-5), NOT the total number of goals scored across all matches.
     *
     * @param array<int,mixed> $matches
     * @return array{match_goals:int, match_missed_goals:int, goals_avg:float, missed_avg:float, considered:int, skipped:int}|null
     */
    private function calculateForTeam(array $matches, string $teamNorm): ?array
    {
        $matchGoals = 0;  // Count of matches with at least 1 goal scored (0-5)
        $matchMissedGoals = 0;  // Count of matches with at least 1 goal conceded (0-5)
        $sumGoals = 0;  // Total goals scored across all matches
        $sumMissedGoals = 0;  // Total goals conceded across all matches
        $considered = 0;
        $skipped = 0;

        foreach ($matches as $m) {
            if (!is_array($m)) {
                $skipped++;
                continue;
            }

            $scores = $this->extractHtScores($m, $teamNorm);
            if ($scores === null) {
                $skipped++;
                continue;
            }

            $considered++;
            $sumGoals += $scores['team'];
            $sumMissedGoals += $scores['opp'];

            // Increment match counter if team scored at least 1 goal in HT
            if ($scores['team'] > 0) {
                $matchGoals++;
            }
            // Increment match counter if team conceded at least 1 goal in HT
            if ($scores['opp'] > 0) {
                $matchMissedGoals++;
            }
        }

        if ($considered <= 0) {
            return null;
        }

        return [
            'match_goals' => $matchGoals,
            'match_missed_goals' => $matchMissedGoals,
            'goals_avg' => $sumGoals / $considered,
            'missed_avg' => $sumMissedGoals / $considered,
            'considered' => $considered,
            'skipped' => $skipped,
        ];
    }

    /**
     * Extract HT scores for a specific team from a match.
     *
     * @return array{team:int, opp:int}|null
     */
    private function extractHtScores(array $match, string $teamNorm): ?array
    {
        $homeTeam = TeamNameNormalizer::normalize($this->extractTeamName($match, 'H'));
        $awayTeam = TeamNameNormalizer::normalize($this->extractTeamName($match, 'A'));
        
        if ($homeTeam === '' || $awayTeam === '') {
            return null;
        }

        $p0 = $match['P'][0] ?? null;
        if (!is_array($p0) || !array_key_exists('H', $p0) || !array_key_exists('A', $p0)) {
            return null;
        }

        if (!is_numeric($p0['H']) || !is_numeric($p0['A'])) {
            return null;
        }

        $h = (int) $p0['H'];
        $a = (int) $p0['A'];

        if ($teamNorm === $homeTeam) {
            return ['team' => $h, 'opp' => $a];
        }

        if ($teamNorm === $awayTeam) {
            return ['team' => $a, 'opp' => $h];
        }

        return null;
    }

    /**
     * Extract team name from match data.
     */
    private function extractTeamName(array $data, string $side): string
    {
        $obj = $data[$side] ?? null;
        if (!is_array($obj)) {
            return '';
        }

        $t = $obj['T'] ?? null;
        if (is_string($t)) {
            return trim($t);
        }

        if (is_array($t) && isset($t['T']) && is_string($t['T'])) {
            return trim($t['T']);
        }

        return '';
    }

    /**
     * Calculate weighted form metrics for Algorithm 1 v2.
     *
     * Uses exponential decay weights for last 5 matches:
     * - Most recent: 0.35
     * - 2nd: 0.28
     * - 3rd: 0.20
     * - 4th: 0.12
     * - 5th: 0.05
     *
     * @param array<int,mixed> $matches
     * @param string $teamNorm Normalized team name
     * @return array{attack:float, defense:float, valid_matches:int}|null
     */
    private function calculateWeightedForm(array $matches, string $teamNorm): ?array
    {
        $weights = [0.35, 0.28, 0.20, 0.12, 0.05];
        $attackSum = 0.0;
        $defenseSum = 0.0;
        $validMatches = 0;

        foreach ($matches as $idx => $m) {
            if ($idx >= 5) {
                break; // Only process first 5 matches
            }
            if (!is_array($m)) {
                continue;
            }

            $scores = $this->extractHtScores($m, $teamNorm);
            if ($scores === null) {
                continue;
            }

            $weight = $weights[$idx] ?? 0.0;
            $attackSum += $scores['team'] * $weight;
            $defenseSum += $scores['opp'] * $weight;
            $validMatches++;
        }

        if ($validMatches === 0) {
            return null;
        }

        return [
            'attack' => $attackSum,
            'defense' => $defenseSum,
            'valid_matches' => $validMatches,
        ];
    }

    /**
     * Calculate weighted form score combining home and away metrics.
     *
     * Formula from Algorithm 1 v2 spec:
     * (home_attack * 0.6 + away_defense * 0.4 + away_attack * 0.6 + home_defense * 0.4) / 2
     *
     * @param array{attack:float, defense:float, valid_matches:int}|null $homeForm
     * @param array{attack:float, defense:float, valid_matches:int}|null $awayForm
     * @return float|null
     */
    private function calculateWeightedFormScore(?array $homeForm, ?array $awayForm): ?float
    {
        if ($homeForm === null || $awayForm === null) {
            return null;
        }

        $score = (
            $homeForm['attack'] * 0.6 +
            $awayForm['defense'] * 0.4 +
            $awayForm['attack'] * 0.6 +
            $homeForm['defense'] * 0.4
        ) / 2.0;

        return $score;
    }

    /**
     * Get first N elements from array.
     *
     * @return array<int,mixed>
     */
    private function getFirstN(array $list, int $n = 5): array
    {
        if ($n <= 0) {
            return [];
        }

        return array_slice(array_values($list), 0, $n);
    }
}
