<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms;

use Proxbet\Core\Interfaces\AlgorithmInterface;
use Proxbet\Scanner\MatchFilter;

/**
 * Algorithm 3: Team total prediction based on table statistics.
 */
final class AlgorithmThree implements AlgorithmInterface
{
    private const ALGORITHM_ID = 3;
    private const ALGORITHM_NAME = 'Алгоритм 3';

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
        $algorithmData = $matchData['algorithm_data'] ?? [];

        $decision = $this->filter->shouldBetAlgorithmThree($algorithmData);

        return [
            'bet' => $decision['bet'] ?? false,
            'reason' => $decision['reason'] ?? 'unknown',
            'confidence' => 0.0, // Algorithm 3 doesn't use probability
        ];
    }
}
