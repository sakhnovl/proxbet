<?php

declare(strict_types=1);

namespace Proxbet\Core\Interfaces;

/**
 * Interface for betting algorithms.
 */
interface AlgorithmInterface
{
    /**
     * Analyze match and return decision.
     *
     * @param array<string,mixed> $matchData
     * @return array{bet:bool,reason:string,confidence:float}
     */
    public function analyze(array $matchData): array;

    /**
     * Get algorithm ID.
     *
     * @return int
     */
    public function getId(): int;

    /**
     * Get algorithm name.
     *
     * @return string
     */
    public function getName(): string;
}
