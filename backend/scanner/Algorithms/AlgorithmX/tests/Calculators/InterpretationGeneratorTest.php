<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests\Calculators;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\InterpretationGenerator;

final class InterpretationGeneratorTest extends TestCase
{
    private InterpretationGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new InterpretationGenerator();
    }

    public function testGenerateReturnsLowActivityText(): void
    {
        $text = $this->generator->generate(0.10);

        $this->assertNotEmpty($text);
        $this->assertNotSame($text, $this->generator->generate(0.30));
    }

    public function testGenerateReturnsDifferentBands(): void
    {
        $low = $this->generator->generate(0.10);
        $medium = $this->generator->generate(0.50);
        $high = $this->generator->generate(0.85);

        $this->assertNotSame($low, $medium);
        $this->assertNotSame($medium, $high);
    }
}
