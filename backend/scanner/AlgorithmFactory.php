<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Core\Interfaces\AlgorithmInterface;
use Proxbet\Scanner\Algorithms\AlgorithmOne;
use Proxbet\Scanner\Algorithms\AlgorithmTwo;
use Proxbet\Scanner\Algorithms\AlgorithmThree;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator as AlgorithmOneProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\FormScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\H2hScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\LiveScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ProbabilityCalculatorV2;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\PdiCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ShotQualityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TrendCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TimePressureCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\LeagueFactorCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\CardFactorCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\XgPressureCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\RedFlagChecker;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\DualRunService;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Config as AlgorithmOneConfig;

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
            1 => $this->createAlgorithmOne(),
            2 => new AlgorithmTwo($this->filter),
            3 => new AlgorithmThree($this->filter),
            default => throw new \InvalidArgumentException("Unknown algorithm ID: {$algorithmId}"),
        };
    }

    /**
     * Create AlgorithmOne with all dependencies.
     */
    private function createAlgorithmOne(): AlgorithmOne
    {
        // Build legacy calculator
        $formCalculator = new FormScoreCalculator();
        $h2hCalculator = new H2hScoreCalculator();
        $liveCalculator = new LiveScoreCalculator();
        $legacyCalculator = new AlgorithmOneProbabilityCalculator(
            $formCalculator,
            $h2hCalculator,
            $liveCalculator
        );

        // Build V2 calculator
        $v2Calculator = new ProbabilityCalculatorV2(
            new PdiCalculator(),
            new ShotQualityCalculator(),
            new TrendCalculator(),
            new TimePressureCalculator(),
            new LeagueFactorCalculator(),
            new CardFactorCalculator(),
            new XgPressureCalculator(),
            new RedFlagChecker()
        );

        // Build filter
        $legacyFilter = new LegacyFilter();

        // Build dual-run service if enabled
        $dualRunService = null;
        if (AlgorithmOneConfig::isDualRunEnabled()) {
            $dualRunService = new DualRunService($legacyCalculator, $v2Calculator, $legacyFilter);
        }

        return new AlgorithmOne($legacyCalculator, $v2Calculator, $legacyFilter, $dualRunService);
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
