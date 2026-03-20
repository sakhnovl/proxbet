<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

final class SgiJsonValidator
{
    /**
     * Validate SGI JSON structure.
     *
     * @param array<string,mixed> $data
     * @return ValidationResult
     */
    public function validate(array $data): ValidationResult
    {
        $errors = [];
        $warnings = [];

        // Check for home team data
        if (!isset($data['H']) || !is_array($data['H'])) {
            $errors[] = 'Missing or invalid home team data (H)';
        } else {
            if (!$this->hasTeamName($data['H'])) {
                $warnings[] = 'Home team (H) missing team name (T)';
            }
        }

        // Check for away team data
        if (!isset($data['A']) || !is_array($data['A'])) {
            $errors[] = 'Missing or invalid away team data (A)';
        } else {
            if (!$this->hasTeamName($data['A'])) {
                $warnings[] = 'Away team (A) missing team name (T)';
            }
        }

        // Check for last matches data (Q)
        if (isset($data['Q'])) {
            if (!is_array($data['Q'])) {
                $warnings[] = 'Invalid last matches data (Q) - expected array';
            }
        }

        // Check for head-to-head data (G)
        if (isset($data['G'])) {
            if (!is_array($data['G'])) {
                $warnings[] = 'Invalid head-to-head data (G) - expected array';
            }
        }

        // Check for tournament table data (S)
        if (isset($data['S'])) {
            if (!is_array($data['S'])) {
                $warnings[] = 'Invalid tournament table data (S) - expected array';
            } else {
                $this->validateTableStructure($data['S'], $warnings);
            }
        }

        return new ValidationResult(
            valid: count($errors) === 0,
            errors: $errors,
            warnings: $warnings
        );
    }

    /**
     * @param array<string,mixed> $teamData
     */
    private function hasTeamName(array $teamData): bool
    {
        $t = $teamData['T'] ?? null;
        
        if (is_string($t) && trim($t) !== '') {
            return true;
        }

        if (is_array($t) && isset($t['T']) && is_string($t['T']) && trim($t['T']) !== '') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $tableData
     * @param array<int,string> $warnings
     */
    private function validateTableStructure(array $tableData, array &$warnings): void
    {
        if (!isset($tableData['A']) || !is_array($tableData['A'])) {
            $warnings[] = 'Tournament table (S) missing standings data (A)';
            return;
        }

        $standings = $tableData['A'];
        if (!isset($standings['C']) || !is_array($standings['C'])) {
            $warnings[] = 'Tournament standings (S.A) missing groups data (C)';
        }
    }
}
