<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Services;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\GeminiAnalyzer;

final class GeminiAnalyzerTest extends TestCase
{
    public function testBuildPromptMapsRealV2FieldsAndAuditConstraints(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $prompt = $this->buildPrompt($analyzer, [
            'algorithm_version' => Config::VERSION_V2,
            'home' => 'Alpha',
            'away' => 'Beta',
            'league' => 'Premier',
            'country' => 'England',
            'time' => '20:00',
            'minute' => 27,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'bet' => true,
            'probability' => 0.64,
            'reason' => 'probability_threshold_met',
            'gating_passed' => true,
            'gating_reason' => '',
            'red_flags' => ['low_accuracy'],
            'penalties' => ['low_accuracy' => 0.88, 'missing_h2h' => 0.94],
            'gating_context' => [
                'has_h2h_data' => false,
                'shots_gate_relief' => true,
                'league_profile' => ['category' => 'top_tier', 'probability_threshold' => 0.55],
            ],
            'components' => [
                'pdi' => 0.81,
                'shot_quality' => 0.74,
                'trend_acceleration' => 0.62,
                'time_pressure' => 0.58,
                'component_contributions' => [
                    'form_pre_penalty' => 0.18,
                    'h2h_pre_penalty' => 0.07,
                    'live_pre_penalty' => 0.39,
                    'form_final' => 0.16,
                    'h2h_final' => 0.06,
                    'live_final' => 0.34,
                    'live_components_final' => [
                        'pdi' => 0.12,
                        'shot_quality' => 0.08,
                    ],
                ],
                'probability_breakdown' => [
                    'form_score' => 0.71,
                    'h2h_score' => 0.50,
                    'live_score_adjusted' => 0.83,
                    'time_pressure_multiplier' => 1.0,
                    'base_probability' => 0.72,
                    'pre_penalty_probability' => 0.72,
                    'final_probability' => 0.64,
                    'probability_threshold' => 0.55,
                ],
                'threshold_evaluation' => [
                    'active' => 0.55,
                    'candidates' => ['0.55' => true, '0.60' => true],
                ],
            ],
        ]);

        $this->assertStringContainsString('=== INPUT CONTRACT ===', $prompt);
        $this->assertStringContainsString('=== AUDIT GOALS ===', $prompt);
        $this->assertStringContainsString('=== DECISION PRIORITY ===', $prompt);
        $this->assertStringContainsString('=== INTERPRETATION RULES ===', $prompt);
        $this->assertStringContainsString('=== RESPONSE LIMITS ===', $prompt);
        $this->assertStringContainsString('=== JSON RESPONSE CONTRACT ===', $prompt);
        $this->assertStringContainsString('Probability: 64.00% (raw=0.64)', $prompt);
        $this->assertStringContainsString('Trend acceleration: 0.62', $prompt);
        $this->assertStringContainsString('Gating passed: yes', $prompt);
        $this->assertStringContainsString('- low_accuracy', $prompt);
        $this->assertStringContainsString('- missing_h2h: 0.94', $prompt);
        $this->assertStringContainsString('- active: 0.55', $prompt);
        $this->assertStringContainsString('- candidates.0.55: yes', $prompt);
        $this->assertStringContainsString('- pre_penalty_probability: 0.72', $prompt);
        $this->assertStringContainsString('- final_probability: 0.64', $prompt);
        $this->assertStringContainsString('- time_pressure_multiplier: 1.00', $prompt);
        $this->assertStringContainsString('- form_final: 0.16', $prompt);
        $this->assertStringContainsString('- live_components_final.pdi: 0.12', $prompt);
        $this->assertStringContainsString('- has_h2h_data: no', $prompt);
        $this->assertStringContainsString('- league_profile.category: top_tier', $prompt);
        $this->assertStringContainsString('- league_profile.probability_threshold: 0.55', $prompt);
        $this->assertStringContainsString('1. gating', $prompt);
        $this->assertStringContainsString('2. red flags', $prompt);
        $this->assertStringContainsString('3. penalties', $prompt);
        $this->assertStringContainsString('If `gating_passed = false`, verdict should almost never be `support`.', $prompt);
        $this->assertStringContainsString('- `key_factors` must contain at most 3 items.', $prompt);
        $this->assertStringNotContainsString('{{trend_acceleration}}', $prompt);
        $this->assertStringNotContainsString('{{danger_trend}}', $prompt);
    }

    public function testBuildPromptUsesBreakdownFallbackForComponentScores(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $prompt = $this->buildPrompt($analyzer, [
            'algorithm_version' => Config::VERSION_V2,
            'probability' => 0.52,
            'components' => [
                'probability_breakdown' => [
                    'form_score' => 0.66,
                    'h2h_score' => 0.40,
                    'live_score_adjusted' => 0.77,
                ],
                'threshold_evaluation' => ['active' => 0.52],
            ],
        ]);

        $this->assertStringContainsString('- Form: 0.66', $prompt);
        $this->assertStringContainsString('- H2H: 0.40', $prompt);
        $this->assertStringContainsString('- Live: 0.77', $prompt);
        $this->assertStringContainsString('- final_probability: n/a', $prompt);
        $this->assertStringContainsString('- form_pre_penalty: n/a', $prompt);
    }

    public function testBuildPromptKeepsAuditResponseTemplateCompact(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $prompt = $this->buildPrompt($analyzer, [
            'algorithm_version' => Config::VERSION_V2,
        ]);

        $this->assertStringContainsString('"verdict": "support|doubt|reject"', $prompt);
        $this->assertStringContainsString('"component_checks": {', $prompt);
        $this->assertStringContainsString('"contradictions": []', $prompt);
        $this->assertStringContainsString('Return exactly one JSON object and nothing else.', $prompt);
    }

    public function testBuildPromptUsesUnifiedPlaceholderForMissingContextBlocks(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $prompt = $this->buildPrompt($analyzer, [
            'algorithm_version' => Config::VERSION_V2,
            'gating_passed' => false,
        ]);

        $this->assertStringContainsString('Gating passed: no', $prompt);
        $this->assertStringContainsString('Gating reason: n/a', $prompt);
        $this->assertStringContainsString("Penalties:\n- n/a", $prompt);
        $this->assertStringContainsString('- active: n/a', $prompt);
        $this->assertStringContainsString('- final_probability: n/a', $prompt);
        $this->assertStringContainsString('- form_final: n/a', $prompt);
    }

    public function testBuildPromptUsesDedicatedExplainTemplate(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $prompt = $this->buildPrompt($analyzer, [
            'algorithm_version' => Config::VERSION_V2,
            'probability' => 0.64,
            'gating_passed' => true,
        ], GeminiAnalyzer::MODE_EXPLAIN);

        $this->assertStringContainsString('=== EXPLAIN MODE ===', $prompt);
        $this->assertStringContainsString('=== LANGUAGE RULE ===', $prompt);
        $this->assertStringContainsString('- Write the full answer in Russian.', $prompt);
        $this->assertStringContainsString('=== STYLE RESTRICTIONS ===', $prompt);
        $this->assertStringContainsString('- Do not start with phrases like "Алгоритм рекомендует"', $prompt);
        $this->assertStringContainsString('You should not just repeat the algorithm result.', $prompt);
        $this->assertStringContainsString('- Analyze the match using both the live match data and the algorithm context.', $prompt);
        $this->assertStringContainsString('- Return plain text only.', $prompt);
        $this->assertStringContainsString('- Do not return JSON.', $prompt);
        $this->assertStringContainsString('1. Match reading: what the game looks like right now', $prompt);
        $this->assertStringContainsString('2. Main reasons: combine the strongest match signals', $prompt);
        $this->assertStringContainsString('The answer must feel like a short football match analysis', $prompt);
        $this->assertStringNotContainsString('=== JSON RESPONSE CONTRACT ===', $prompt);
    }

    public function testBuildPromptUsesDedicatedChannelShortTemplate(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $prompt = $this->buildPrompt($analyzer, [
            'algorithm_version' => Config::VERSION_V2,
            'probability' => 0.67,
            'shots_total' => 9,
            'shots_on_target_total' => 4,
            'dangerous_attacks_total' => 51,
            'corners_total' => 3,
        ], GeminiAnalyzer::MODE_CHANNEL_SHORT);

        $this->assertStringContainsString('=== CHANNEL SHORT MODE ===', $prompt);
        $this->assertStringContainsString('- Maximum length: 10 words.', $prompt);
        $this->assertStringContainsString('- Return exactly one short line.', $prompt);
        $this->assertStringContainsString('- Do not say that the algorithm recommends, considers, or suggests the bet.', $prompt);
        $this->assertStringContainsString('Shots total: 9', $prompt);
        $this->assertStringContainsString('Dangerous attacks total: 51', $prompt);
        $this->assertStringNotContainsString('=== JSON RESPONSE CONTRACT ===', $prompt);
    }

    public function testParseResponseDecodesAndNormalizesJsonContract(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $analysis = $this->parseResponse($analyzer, json_encode([
            'verdict' => 'support',
            'confidence' => 82.4,
            'summary' => 'Decision is aligned with gating.',
            'component_checks' => [
                'form' => 'Stable form signal',
                'h2h' => 'Limited H2H support',
                'live' => 'Live pressure is strong',
                'gating' => 'Passed without blockers',
                'red_flags' => 'Only one soft flag',
            ],
            'key_factors' => ['factor 1', 'factor 2', 'factor 3', 'factor 4'],
            'risks' => ['risk 1', 'risk 2', 'risk 3', 'risk 4'],
            'contradictions' => ['contradiction 1', 'contradiction 2', 'contradiction 3', 'contradiction 4'],
            'recommendation' => 'Allow audit support.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertSame('support', $analysis['verdict']);
        $this->assertSame(82, $analysis['confidence']);
        $this->assertSame('Decision is aligned with gating.', $analysis['summary']);
        $this->assertSame('Stable form signal', $analysis['component_checks']['form']);
        $this->assertCount(3, $analysis['key_factors']);
        $this->assertCount(3, $analysis['risks']);
        $this->assertCount(3, $analysis['contradictions']);
        $this->assertSame('Allow audit support.', $analysis['recommendation']);
        $this->assertNull($analysis['parse_error']);
    }

    public function testParseResponseReturnsControlledFallbackForInvalidJson(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $response = "Verdict: support\nConfidence: 90";
        $analysis = $this->parseResponse($analyzer, $response);

        $this->assertSame('unknown', $analysis['verdict']);
        $this->assertSame(0, $analysis['confidence']);
        $this->assertSame([], $analysis['key_factors']);
        $this->assertSame([], $analysis['risks']);
        $this->assertSame([], $analysis['contradictions']);
        $this->assertSame($response, $analysis['raw_response']);
        $this->assertSame('Gemini returned invalid JSON audit response', $analysis['parse_error']);
    }

    public function testParseResponseReturnsPlainTextContractForExplainMode(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $response = "Decision stays supportive.\n\nLive pressure is acceptable.\n\nMain risk is missing H2H depth.";
        $analysis = $this->parseResponse($analyzer, $response, GeminiAnalyzer::MODE_EXPLAIN);

        $this->assertSame($response, $analysis['text']);
        $this->assertNull($analysis['parse_error']);
        $this->assertSame($response, $analysis['raw_response']);
        $this->assertArrayNotHasKey('verdict', $analysis);
    }

    public function testParseResponseReturnsControlledFallbackForEmptyExplainMode(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $analysis = $this->parseResponse($analyzer, "```text\n\n```\n", GeminiAnalyzer::MODE_EXPLAIN);

        $this->assertSame('', $analysis['text']);
        $this->assertSame('Gemini returned empty explain response', $analysis['parse_error']);
    }

    public function testParseResponseNormalizesShortChannelSummary(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $analysis = $this->parseResponse(
            $analyzer,
            "\"Темп высокий, давление растет, гол до перерыва выглядит рабочим вариантом\"\nвторая строка",
            GeminiAnalyzer::MODE_CHANNEL_SHORT
        );

        $this->assertSame('Темп высокий, давление растет, гол до перерыва выглядит рабочим вариантом', $analysis['text']);
        $this->assertNull($analysis['parse_error']);
    }

    public function testParseResponseReturnsControlledFallbackForEmptyChannelSummary(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $analysis = $this->parseResponse($analyzer, "```text\n\n```\n", GeminiAnalyzer::MODE_CHANNEL_SHORT);

        $this->assertSame('', $analysis['text']);
        $this->assertSame('Gemini returned empty channel summary response', $analysis['parse_error']);
    }

    public function testParseResponseNormalizesUnknownVerdictAndCodeFenceWrapper(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');
        $analysis = $this->parseResponse($analyzer, <<<JSON
```json
{"verdict":"maybe","confidence":120,"summary":"Wrapped","component_checks":{"live":"ok"}}
```
JSON);

        $this->assertSame('unknown', $analysis['verdict']);
        $this->assertSame(100, $analysis['confidence']);
        $this->assertSame('Wrapped', $analysis['summary']);
        $this->assertSame('ok', $analysis['component_checks']['live']);
        $this->assertNull($analysis['parse_error']);
    }

    public function testAnalyzeRejectsUnsupportedMode(): void
    {
        $analyzer = new GeminiAnalyzer('test-key');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported GeminiAnalyzer mode: invalid');

        $analyzer->analyze([], 'invalid');
    }

    /**
     * @param array<string,mixed> $context
     */
    private function buildPrompt(
        GeminiAnalyzer $analyzer,
        array $context,
        string $mode = GeminiAnalyzer::MODE_AUDIT
    ): string
    {
        $method = new \ReflectionMethod($analyzer, 'buildPrompt');
        $method->setAccessible(true);

        /** @var string $prompt */
        $prompt = $method->invoke($analyzer, $context, $mode);

        return $prompt;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseResponse(
        GeminiAnalyzer $analyzer,
        string $response,
        string $mode = GeminiAnalyzer::MODE_AUDIT
    ): array
    {
        $method = new \ReflectionMethod($analyzer, 'parseResponse');
        $method->setAccessible(true);

        /** @var array<string,mixed> $analysis */
        $analysis = $method->invoke($analyzer, $response, $mode);

        return $analysis;
    }
}
