<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms;

use Proxbet\Core\Interfaces\AlgorithmInterface;
use Proxbet\Scanner\ProbabilityCalculator;
use Proxbet\Scanner\MatchFilter;

/**
 * Algorithm 1: First half goal prediction based on form, H2H, and live data.
 */
final class AlgorithmOne implements AlgorithmInterface
{
    private const ALGORITHM_ID = 1;
    private const ALGORITHM_NAME = 'Алгоритм 1';

    public function __construct(
        private ProbabilityCalculator $calculator,
        private MatchFilter $filter
    ) {
    }

    public function getId(): int
    {
        return self::ALGORITHM_ID;
    }

    public function getName(): string
    {
        return self::ALGORITHM_NAME;
    }

    /**
     * @param array<string,mixed> $matchData
     * @return array{bet:bool,reason:string,confidence:float}
     */
    public function analyze(array $matchData): array
    {
        $formData = $matchData['form_data'] ?? [];
        $h2hData = $matchData['h2h_data'] ?? [];
        $liveData = $matchData['live_data'] ?? [];

        // Calculate scores
        $scores = $this->calculator->calculateAll($formData, $h2hData, $liveData);
        
        $algorithmVersion = $scores['algorithm_version'] ?? 1;
        
        // For v2, use the decision from calculator
        if ($algorithmVersion === 2 && isset($scores['decision'])) {
            return [
                'bet' => $scores['decision']['bet'] ?? false,
                'reason' => $scores['decision']['reason'] ?? 'unknown',
                'confidence' => $scores['probability'] ?? 0.0,
            ];
        }
        
        // For legacy, use filter
        $decision = $this->filter->shouldBetAlgorithmOne(
            $liveData,
            $scores['probability'],
            $formData,
            $h2hData
        );

        return [
            'bet' => $decision['bet'] ?? false,
            'reason' => $decision['reason'] ?? 'unknown',
            'confidence' => $scores['probability'] ?? 0.0,
        ];
    }
}
