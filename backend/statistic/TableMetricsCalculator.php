<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

use Proxbet\Statistic\Interfaces\MetricsCalculatorInterface;

final class TableMetricsCalculator implements MetricsCalculatorInterface
{
    /**
     * @param array<string,mixed> $sgi
     * @return array{
     *   metrics: array<string,int|float|null>,
     *   debug: array<string,mixed>
     * }
     */
    public function calculate(array $sgi, string $home, string $away): array
    {
        $rows = $this->extractRows($sgi);
        $homeNorm = TeamNameNormalizer::normalize($home);
        $awayNorm = TeamNameNormalizer::normalize($away);

        $homeCandidates = [];
        $awayCandidates = [];
        $sumGoals = 0;
        $sumGames = 0;
        $validAvgRows = 0;

        foreach ($rows as $index => $row) {
            $teamName = $this->extractTeamName($row);
            $teamNorm = TeamNameNormalizer::normalize($teamName);

            if ($teamNorm !== '') {
                if ($teamNorm === $homeNorm) {
                    $homeCandidates[] = ['index' => $index, 'team' => $teamName, 'row' => $row];
                }
                if ($teamNorm === $awayNorm) {
                    $awayCandidates[] = ['index' => $index, 'team' => $teamName, 'row' => $row];
                }
            }

            $games = $this->toIntOrNull($row['C'] ?? null);
            $goals = $this->toIntOrNull($row['S'] ?? null);
            if ($games === null || $goals === null || $games <= 0) {
                continue;
            }

            $sumGoals += $goals;
            $sumGames += $games;
            $validAvgRows++;
        }

        $homeStats = $this->extractStats($homeCandidates[0]['row'] ?? null);
        $awayStats = $this->extractStats($awayCandidates[0]['row'] ?? null);
        $warnings = [];

        if (count($homeCandidates) > 1) {
            $warnings[] = 'multiple_home_matches';
        }
        if (count($awayCandidates) > 1) {
            $warnings[] = 'multiple_away_matches';
        }
        if ($homeNorm !== '' && $awayNorm !== '' && $homeNorm === $awayNorm) {
            $warnings[] = 'home_away_normalized_equal';
        }

        return [
            'metrics' => [
                'table_games_1' => $homeStats['games'],
                'table_goals_1' => $homeStats['goals'],
                'table_missed_1' => $homeStats['missed'],
                'table_games_2' => $awayStats['games'],
                'table_goals_2' => $awayStats['goals'],
                'table_missed_2' => $awayStats['missed'],
                'table_avg' => $sumGames > 0 ? round(($sumGoals * 2.0) / $sumGames, 2) : null,
            ],
            'debug' => [
                'source_count' => count($rows),
                'valid_avg_rows' => $validAvgRows,
                'home_input' => $home,
                'away_input' => $away,
                'home_normalized' => $homeNorm,
                'away_normalized' => $awayNorm,
                'home_found' => $homeStats['found'],
                'away_found' => $awayStats['found'],
                'home_matches' => count($homeCandidates),
                'away_matches' => count($awayCandidates),
                'home_selected_index' => $homeCandidates[0]['index'] ?? null,
                'away_selected_index' => $awayCandidates[0]['index'] ?? null,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $sgi
     * @return array<int,array<string,mixed>>
     */
    private function extractRows(array $sgi): array
    {
        $groups = $sgi['S']['A']['C'] ?? null;
        if (!is_array($groups)) {
            return [];
        }

        $out = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $rows = $group['R'] ?? null;
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array{found:bool,games:?int,goals:?int,missed:?int}
     */
    private function extractStats(?array $row): array
    {
        if ($row === null) {
            return ['found' => false, 'games' => null, 'goals' => null, 'missed' => null];
        }

        return [
            'found' => true,
            'games' => $this->toIntOrNull($row['C'] ?? null),
            'goals' => $this->toIntOrNull($row['S'] ?? null),
            'missed' => $this->toIntOrNull($row['F'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractTeamName(array $row): string
    {
        $team = $row['T'] ?? null;
        if (is_string($team)) {
            return trim($team);
        }

        if (!is_array($team)) {
            return '';
        }

        $name = $team['T'] ?? null;
        return is_string($name) ? trim($name) : '';
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
