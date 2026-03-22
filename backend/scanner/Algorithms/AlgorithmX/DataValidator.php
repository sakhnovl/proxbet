<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX;

/**
 * Validates input data for AlgorithmX.
 *
 * Ensures all required fields are present and within valid ranges
 * before probability calculation.
 */
final class DataValidator
{
    /**
     * Validate live data for AlgorithmX analysis.
     *
     * @param array<string,mixed> $data Live data from DataExtractor
     * @return array{valid: bool, reason: string}
     */
    public function validate(array $data): array
    {
        if (empty($data['has_data'])) {
            return [
                'valid' => false,
                'reason' => 'No live data available',
            ];
        }

        $requiredFields = [
            'minute',
            'score_home',
            'score_away',
            'dangerous_attacks_home',
            'dangerous_attacks_away',
            'shots_home',
            'shots_away',
            'shots_on_target_home',
            'shots_on_target_away',
            'corners_home',
            'corners_away',
            'match_status',
        ];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data)) {
                return [
                    'valid' => false,
                    'reason' => "Missing required field: {$field}",
                ];
            }
        }

        $matchStatus = (string) $data['match_status'];
        if ($this->hasInvalidMatchStatus($matchStatus)) {
            return [
                'valid' => false,
                'reason' => "Match status is invalid: {$matchStatus}",
            ];
        }

        $minute = (int) $data['minute'];
        if ($minute < Config::MIN_MINUTE) {
            return [
                'valid' => false,
                'reason' => "Minute {$minute} is below minimum " . Config::MIN_MINUTE,
            ];
        }

        if ($minute > Config::MAX_MINUTE) {
            return [
                'valid' => false,
                'reason' => "Minute {$minute} exceeds maximum " . Config::MAX_MINUTE,
            ];
        }

        $numericFields = [
            'score_home',
            'score_away',
            'dangerous_attacks_home',
            'dangerous_attacks_away',
            'shots_home',
            'shots_away',
            'shots_on_target_home',
            'shots_on_target_away',
            'corners_home',
            'corners_away',
        ];

        foreach ($numericFields as $field) {
            $value = $data[$field];
            if (!is_numeric($value) || $value < 0) {
                return [
                    'valid' => false,
                    'reason' => "Invalid value for {$field}: must be non-negative number",
                ];
            }
        }

        return [
            'valid' => true,
            'reason' => '',
        ];
    }

    private function hasInvalidMatchStatus(string $matchStatus): bool
    {
        if ($matchStatus === '') {
            return false;
        }

        $pattern = '/(\x{0437}\x{0430}\x{0432}\x{0435}\x{0440}\x{0448}|\x{043E}\x{0442}\x{043C}\x{0435}\x{043D}|завершен|2-й тайм|postponed|finished|full\s*time|ft)/iu';

        return preg_match($pattern, $matchStatus) === 1;
    }
}
