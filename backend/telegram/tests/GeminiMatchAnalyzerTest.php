<?php

declare(strict_types=1);

namespace Proxbet\Telegram\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Telegram\GeminiMatchAnalyzer;

/**
 * Тесты для GeminiMatchAnalyzer
 * 
 * Запуск: vendor/bin/phpunit backend/telegram/tests/GeminiMatchAnalyzerTest.php
 */
final class GeminiMatchAnalyzerTest extends TestCase
{
    private string $testApiKey = 'test_api_key_12345';

    public function testConstructorSetsDefaults(): void
    {
        $analyzer = new GeminiMatchAnalyzer($this->testApiKey);
        $this->assertInstanceOf(GeminiMatchAnalyzer::class, $analyzer);
    }

    public function testAnalyzeThrowsExceptionWhenApiKeyEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_API_KEY is not configured');

        $analyzer = new GeminiMatchAnalyzer('');
        $analyzer->analyze([]);
    }

    public function testBuildPromptRejectsLegacyAlgorithmOnePath(): void
    {
        $analyzer = new GeminiMatchAnalyzer($this->testApiKey);
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('buildPrompt');
        $method->setAccessible(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AlgorithmOne prompt is handled by dedicated explain mode');

        $method->invoke($analyzer, ['algorithm_id' => 1]);
    }

    public function testBuildPromptForAlgorithmTwo(): void
    {
        $analyzer = new GeminiMatchAnalyzer($this->testApiKey);
        $context = [
            'algorithm_id' => 2,
            'home' => 'Фаворит',
            'away' => 'Аутсайдер',
            'home_cf' => 1.5,
            'scanner_bet' => 'yes',
        ];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($analyzer, $context);

        $this->assertStringContainsString('Алгоритм 2', $prompt);
        $this->assertStringContainsString('фаворит', $prompt);
    }

    public function testBuildPromptForAlgorithmThree(): void
    {
        $analyzer = new GeminiMatchAnalyzer($this->testApiKey);
        $context = [
            'algorithm_id' => 3,
            'home' => 'Команда 1',
            'away' => 'Команда 2',
            'scanner_algorithm_data' => [
                'selected_team_name' => 'Команда 1',
                'selected_team_target_bet' => 'ИТБ Команда 1 больше 0.5',
            ],
        ];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($analyzer, $context);

        $this->assertStringContainsString('Алгоритм 3', $prompt);
        $this->assertStringContainsString('ИТБ', $prompt);
    }

    public function testBuildPromptForAlgorithmX(): void
    {
        $analyzer = new GeminiMatchAnalyzer($this->testApiKey);
        $context = [
            'algorithm_id' => 4,
            'home' => 'Pressure United',
            'away' => 'Rapid City',
            'time' => '14:00',
            'scanner_reason' => 'High goal probability',
            'scanner_algorithm_data' => [
                'probability' => 0.83,
                'dangerous_attacks_home' => 40,
                'dangerous_attacks_away' => 35,
                'shots_on_target_home' => 10,
                'shots_on_target_away' => 8,
                'debug' => [
                    'ais_rate' => 3.35,
                ],
            ],
        ];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($analyzer, $context);

        $this->assertStringContainsString('AlgorithmX', $prompt);
        $this->assertStringContainsString('Pressure United', $prompt);
        $this->assertStringContainsString('AIS', $prompt);
    }

    public function testNormalizeShortTextTrimsToSingleTelegramLine(): void
    {
        $analyzer = new GeminiMatchAnalyzer($this->testApiKey);
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('normalizeShortText');
        $method->setAccessible(true);

        $text = $method->invoke($analyzer, "```text\nТемп очень высокий и давление стабильно держится весь отрезок\n```");

        $this->assertSame('Темп очень высокий и давление стабильно держится весь отрезок', $text);
    }

    public function testExtractTextFromValidResponse(): void
    {
        $analyzer = new GeminiMatchAnalyzer($this->testApiKey);
        $decoded = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Вердикт: Подходит'],
                            ['text' => 'Уверенность: 80%'],
                        ],
                    ],
                ],
            ],
        ];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractText');
        $method->setAccessible(true);

        $result = $method->invoke($analyzer, $decoded);

        $this->assertStringContainsString('Вердикт: Подходит', $result);
        $this->assertStringContainsString('Уверенность: 80%', $result);
    }

    public function testExtractTextReturnsNullForInvalidResponse(): void
    {
        $analyzer = new GeminiMatchAnalyzer($this->testApiKey);
        $decoded = ['invalid' => 'structure'];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractText');
        $method->setAccessible(true);

        $result = $method->invoke($analyzer, $decoded);

        $this->assertNull($result);
    }

    public function testAlignResponseWithScannerAdjustsConfidence(): void
    {
        $analyzer = new GeminiMatchAnalyzer($this->testApiKey);
        $response = "Вердикт: Подходит\nУверенность: 50%\n\nПричины:\n- тест";
        $context = [
            'algorithm_id' => 1,
            'scanner_probability' => 70,
            'scanner_bet' => 'yes',
            'scanner_reason' => 'Высокая вероятность',
        ];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('alignResponseWithScanner');
        $method->setAccessible(true);

        $result = $method->invoke($analyzer, $response, $context);

        // Уверенность должна быть скорректирована ближе к 70%
        $this->assertStringContainsString('Уверенность:', $result);
        $this->assertStringContainsString('Синхронизация со сканером:', $result);
        $this->assertStringContainsString('70%', $result);
    }
}
