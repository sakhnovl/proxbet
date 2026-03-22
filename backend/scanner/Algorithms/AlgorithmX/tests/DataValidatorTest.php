<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataValidator;

final class DataValidatorTest extends TestCase
{
    private DataValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DataValidator();
    }

    public function testValidateAcceptsWellFormedLiveData(): void
    {
        $result = $this->validator->validate($this->buildValidPayload());

        $this->assertTrue($result['valid']);
        $this->assertSame('', $result['reason']);
    }

    public function testValidateRejectsFinishedMatchBeforeMinuteChecks(): void
    {
        $payload = $this->buildValidPayload();
        $payload['minute'] = 90;
        $payload['match_status'] = 'Finished';

        $result = $this->validator->validate($payload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('status', strtolower($result['reason']));
    }

    public function testValidateRejectsFinishedMatchWithRussianStatus(): void
    {
        $payload = $this->buildValidPayload();
        $matchStatus = json_decode('"\u0417\u0430\u0432\u0435\u0440\u0448\u0451\u043d"', true);

        $this->assertIsString($matchStatus);
        $payload['match_status'] = $matchStatus;

        $result = $this->validator->validate($payload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString($matchStatus, $result['reason']);
    }

    public function testValidateRejectsNegativeValues(): void
    {
        $payload = $this->buildValidPayload();
        $payload['corners_home'] = -1;

        $result = $this->validator->validate($payload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('corners_home', $result['reason']);
    }

    public function testValidateRejectsMissingLiveDataFlag(): void
    {
        $payload = $this->buildValidPayload();
        $payload['has_data'] = false;

        $result = $this->validator->validate($payload);

        $this->assertFalse($result['valid']);
        $this->assertSame('No live data available', $result['reason']);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildValidPayload(): array
    {
        return [
            'minute' => 18,
            'score_home' => 1,
            'score_away' => 0,
            'dangerous_attacks_home' => 15,
            'dangerous_attacks_away' => 11,
            'shots_home' => 9,
            'shots_away' => 7,
            'shots_on_target_home' => 4,
            'shots_on_target_away' => 3,
            'corners_home' => 3,
            'corners_away' => 2,
            'match_status' => 'In Play',
            'has_data' => true,
        ];
    }
}
