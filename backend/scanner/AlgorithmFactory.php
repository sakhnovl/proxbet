<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Core\Interfaces\AlgorithmInterface;
use Proxbet\Scanner\Algorithms\AlgorithmOne;
use Proxbet\Scanner\Algorithms\AlgorithmTwo;
use Proxbet\Scanner\Algorithms\AlgorithmThree;

/**
 * Factory for creating algorithm instances.
 */
final class AlgorithmFactory
{
    public function __construct(
        private ProbabilityCalculator $calculator,
        private MatchFilter $filter
    ) {
    }

    /**
     * Create algorithm by ID.
     *
     * @param int $algorithmId
     * @return AlgorithmInterface
     * @throws \InvalidArgumentException
     */
    public function create(int $algorithmId): AlgorithmInterface
    {
        return match ($algorithmId) {
            1 => new AlgorithmOne($this->calculator, $this->filter),
            2 => new AlgorithmTwo($this->filter),
            3 => new AlgorithmThree($this->filter),
            default => throw new \InvalidArgumentException("Unknown algorithm ID: {$algorithmId}"),
        };
    }

    /**
     * Get all available algorithms.
     *
     * @return array<int,AlgorithmInterface>
     */
    public function createAll(): array
    {
        return [
            $this->create(1),
            $this->create(2),
            $this->create(3),
        ];
    }
}
