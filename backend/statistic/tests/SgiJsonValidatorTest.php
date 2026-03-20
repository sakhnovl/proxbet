<?php

declare(strict_types=1);

namespace Proxbet\Statistic\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Statistic\SgiJsonValidator;

final class SgiJsonValidatorTest extends TestCase
{
    private SgiJsonValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SgiJsonValidator();
    }

    public function testValidSgiData(): void
    {
        $data = [
            'H' => ['T' => 'Home Team'],
            'A' => ['T' => 'Away Team'],
            'Q' => [],
            'G' => [],
            'S' => ['A' => ['C' => []]],
        ];

        $result = $this->validator->validate($data);

        $this->assertTrue($result->valid);
        $this->assertCount(0, $result->errors);
    }

    public function testMissingHomeTeam(): void
    {
        $data = [
            'A' => ['T' => 'Away Team'],
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->valid);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('home team', strtolower($result->errors[0]));
    }

    public function testMissingAwayTeam(): void
    {
        $data = [
            'H' => ['T' => 'Home Team'],
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->valid);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('away team', strtolower($result->errors[0]));
    }

    public function testMissingTeamNames(): void
    {
        $data = [
            'H' => [],
            'A' => [],
        ];

        $result = $this->validator->validate($data);

        $this->assertTrue($result->valid);
        $this->assertCount(2, $result->warnings);
    }

    public function testInvalidQData(): void
    {
        $data = [
            'H' => ['T' => 'Home Team'],
            'A' => ['T' => 'Away Team'],
            'Q' => 'invalid',
        ];

        $result = $this->validator->validate($data);

        $this->assertTrue($result->valid);
        $this->assertGreaterThan(0, count($result->warnings));
    }

    public function testValidationResultToArray(): void
    {
        $data = [
            'H' => ['T' => 'Home Team'],
            'A' => ['T' => 'Away Team'],
        ];

        $result = $this->validator->validate($data);
        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('valid', $array);
        $this->assertArrayHasKey('errors', $array);
        $this->assertArrayHasKey('warnings', $array);
    }
}
