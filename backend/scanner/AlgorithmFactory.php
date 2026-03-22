<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Core\Interfaces\AlgorithmInterface;
use Proxbet\Scanner\Algorithms\AlgorithmOne;
use Proxbet\Scanner\Algorithms\AlgorithmTwo;
use Proxbet\Scanner\Algorithms\AlgorithmThree;
use Proxbet\Scanner\Algorithms\AlgorithmX\AlgorithmX;
use Proxbet\Scanner\Algorithms\AlgorithmX\Config as AlgorithmXConfig;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataExtractor as AlgorithmXDataExtractor;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataValidator as AlgorithmXDataValidator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\AisCalculator as AlgorithmXAisCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ModifierCalculator as AlgorithmXModifierCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\InterpretationGenerator as AlgorithmXInterpretationGenerator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ProbabilityCalculator as AlgorithmXProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Filters\DecisionFilter as AlgorithmXDecisionFilter;
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
        ProbabilityCalculator $calculator,
        private MatchFilter $filter
    ) {
        unset($calculator);
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
            4 => $this->createAlgorithmX(),
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
     * Create AlgorithmX with all dependencies.
     */
    private function createAlgorithmX(): AlgorithmX
    {
        $config = new AlgorithmXConfig();
        $extractor = new AlgorithmXDataExtractor();
        $validator = new AlgorithmXDataValidator();
        
        $aisCalculator = new AlgorithmXAisCalculator();
        $modifierCalculator = new AlgorithmXModifierCalculator();
        $interpretationGenerator = new AlgorithmXInterpretationGenerator();
        
        $probabilityCalculator = new AlgorithmXProbabilityCalculator(
            $aisCalculator,
            $modifierCalculator,
            $interpretationGenerator
        );
        
        $decisionFilter = new AlgorithmXDecisionFilter();
        
        return new AlgorithmX(
            $config,
            $extractor,
            $validator,
            $probabilityCalculator,
            $decisionFilter
        );
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
            $this->create(4),
        ];
    }
}
