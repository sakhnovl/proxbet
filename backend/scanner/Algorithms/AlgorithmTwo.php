<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms;

use Proxbet\Core\Interfaces\AlgorithmInterface;
use Proxbet\Scanner\MatchFilter;

/**
 * Algorithm 2: First half goal prediction based on specific criteria.
 */
final class AlgorithmTwo implements AlgorithmInterface
{
    private const ALGORITHM_ID = 2;
    private const ALGORITHM_NAME = 'Алгоритм 2';

    public function __construct(
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
        $liveData = $matchData['live_data'] ?? [];
        $algorithmData = $matchData['algorithm_data'] ?? [];

        $decision = $this->filter->shouldBetAlgorithmTwo($liveData, $algorithmData);

        return [
            'bet' => $decision['bet'] ?? false,
            'reason' => $decision['reason'] ?? 'unknown',
            'confidence' => 0.0, // Algorithm 2 doesn't use probability
        ];
    }
}
