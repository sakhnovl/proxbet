<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Services;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

/**
 * AI-powered analysis service for AlgorithmOne predictions.
 *
 * Provides intelligent validation and reasoning for AlgorithmOne's
 * probability-based first half goal predictions using Gemini API.
 */
final class GeminiAnalyzer
{
    public const MODE_AUDIT = 'audit';
    public const MODE_EXPLAIN = 'explain';
    public const MODE_CHANNEL_SHORT = 'channel_short';

    private const MAX_LIST_ITEMS = 3;
    private const ALLOWED_VERDICTS = ['support', 'doubt', 'reject'];
    private const ALLOWED_MODES = [
        self::MODE_AUDIT,
        self::MODE_EXPLAIN,
        self::MODE_CHANNEL_SHORT,
    ];
    private const PLACEHOLDER = 'n/a';
    private const BOOL_YES = 'yes';
    private const BOOL_NO = 'no';
    private const PROBABILITY_BREAKDOWN_KEYS = [
        'time_pressure_multiplier',
        'probability_threshold',
        'base_probability',
        'pre_penalty_probability',
        'final_probability',
    ];
    private const COMPONENT_CONTRIBUTION_KEYS = [
        'form_pre_penalty',
        'h2h_pre_penalty',
        'live_pre_penalty',
        'form_final',
        'h2h_final',
        'live_final',
        'live_components_final',
    ];

    public function __construct(
        private string $apiKey,
        private string $model = 'gemini-2.0-flash',
        private int $timeoutSeconds = 25
    ) {
    }

    /**
     * Analyze AlgorithmOne prediction with AI reasoning.
     *
     * @param array<string,mixed> $context Match and algorithm data
     * @return array{provider:string,model:string,mode:string,prompt:string,response:string,analysis:array<string,mixed>}
     */
    public function analyze(array $context, string $mode = self::MODE_AUDIT): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY is not configured');
        }

        $mode = $this->normalizeMode($mode);
        $prompt = $this->buildPrompt($context, $mode);
        $response = $this->callGeminiApi($prompt, $mode);
        $parsed = $this->parseResponse($response, $mode);

        return [
            'provider' => 'gemini',
            'model' => $this->model,
            'mode' => $mode,
            'prompt' => $prompt,
            'response' => $response,
            'analysis' => $parsed,
        ];
    }

    /**
     * Build specialized prompt for AlgorithmOne analysis.
     * Loads template from external file and renders with context data.
     *
     * @param array<string,mixed> $context
     */
    private function buildPrompt(array $context, string $mode = self::MODE_AUDIT): string
    {
        $templatePath = $this->getTemplatePath($mode);

        if (!file_exists($templatePath)) {
            throw new \RuntimeException('Prompt template not found: ' . $templatePath);
        }

        $template = file_get_contents($templatePath);
        if ($template === false) {
            throw new \RuntimeException('Failed to read prompt template');
        }

        return $this->renderTemplate($template, $context);
    }

    private function getTemplatePath(string $mode): string
    {
        return match ($this->normalizeMode($mode)) {
            self::MODE_EXPLAIN => __DIR__ . '/../prompt_template_explain.txt',
            self::MODE_CHANNEL_SHORT => __DIR__ . '/../prompt_template_channel_short.txt',
            default => __DIR__ . '/../prompt_template.txt',
        };
    }

    /**
     * Render template with context variables.
     *
     * @param array<string,mixed> $context
     */
    private function renderTemplate(string $template, array $context): string
    {
        $version = $context['algorithm_version'] ?? Config::VERSION_LEGACY;
        $isV2 = $version === Config::VERSION_V2;
        $components = is_array($context['components'] ?? null) ? $context['components'] : [];
        $probabilityBreakdown = is_array($components['probability_breakdown'] ?? null)
            ? $components['probability_breakdown']
            : [];
        $thresholdEvaluation = is_array($components['threshold_evaluation'] ?? null)
            ? $components['threshold_evaluation']
            : [];
        $componentContributions = is_array($components['component_contributions'] ?? null)
            ? $components['component_contributions']
            : [];
        $penalties = is_array($context['penalties'] ?? null)
            ? $context['penalties']
            : (is_array($components['penalties'] ?? null) ? $components['penalties'] : []);
        $redFlags = is_array($context['red_flags'] ?? null)
            ? $context['red_flags']
            : (is_array($components['red_flags'] ?? null) ? $components['red_flags'] : []);
        $gatingContext = is_array($context['gating_context'] ?? null)
            ? $context['gating_context']
            : (is_array($components['gating_context'] ?? null) ? $components['gating_context'] : []);
        $probability = $context['probability'] ?? null;

        $vars = [
            'algorithm_version' => (string) $version,
            'home' => $this->val($context['home'] ?? null),
            'away' => $this->val($context['away'] ?? null),
            'league' => $this->val($context['league'] ?? null),
            'country' => $this->val($context['country'] ?? null),
            'time' => $this->val($context['time'] ?? null),
            'minute' => $this->val($context['minute'] ?? null),
            'live_hscore' => $this->val($context['live_hscore'] ?? null),
            'live_ascore' => $this->val($context['live_ascore'] ?? null),
            'bet_decision' => ($context['bet'] ?? false) ? 'BET' : 'NO_BET',
            'probability_raw' => $this->val($probability),
            'probability_percent' => $this->formatPercent($probability),
            'reason' => $this->val($context['reason'] ?? null),
            'form_score' => $this->val($components['form_score'] ?? ($probabilityBreakdown['form_score'] ?? null)),
            'h2h_score' => $this->val(
                $components['h2h_score']
                ?? ($components['h2h_score_effective'] ?? ($probabilityBreakdown['h2h_score'] ?? null))
            ),
            'live_score' => $this->val(
                $components['live_score']
                ?? ($probabilityBreakdown['live_score_adjusted'] ?? ($probabilityBreakdown['live_score_base'] ?? null))
            ),
            'pdi' => $this->val($components['pdi'] ?? null),
            'shot_quality' => $this->val($components['shot_quality'] ?? null),
            'trend_acceleration' => $this->val($components['trend_acceleration'] ?? ($components['danger_trend'] ?? null)),
            'time_pressure' => $this->val($components['time_pressure'] ?? null),
            'gating_passed' => $this->val($context['gating_passed'] ?? null),
            'gating_reason' => $this->val($context['gating_reason'] ?? null),
            'red_flags_list' => $this->formatBulletList($redFlags, self::PLACEHOLDER),
            'penalties_list' => $this->formatKeyValueList($penalties, self::PLACEHOLDER),
            'threshold_evaluation_block' => $this->formatKeyValueList(
                $this->prepareThresholdEvaluation($thresholdEvaluation),
                self::PLACEHOLDER
            ),
            'probability_breakdown_block' => $this->formatKeyValueList(
                $this->prepareProbabilityBreakdown($probabilityBreakdown),
                self::PLACEHOLDER
            ),
            'component_contributions_block' => $this->formatKeyValueList(
                $this->prepareComponentContributions($componentContributions),
                self::PLACEHOLDER
            ),
            'gating_context_list' => $this->formatKeyValueList($gatingContext, self::PLACEHOLDER),
            'live_xg_home' => $this->val($context['live_xg_home'] ?? null),
            'live_xg_away' => $this->val($context['live_xg_away'] ?? null),
            'live_shots_on_target_home' => $this->val($context['live_shots_on_target_home'] ?? null),
            'live_shots_on_target_away' => $this->val($context['live_shots_on_target_away'] ?? null),
            'live_danger_att_home' => $this->val($context['live_danger_att_home'] ?? null),
            'live_danger_att_away' => $this->val($context['live_danger_att_away'] ?? null),
            'live_att_home' => $this->val($context['live_att_home'] ?? null),
            'live_att_away' => $this->val($context['live_att_away'] ?? null),
            'live_corner_home' => $this->val($context['live_corner_home'] ?? null),
            'live_corner_away' => $this->val($context['live_corner_away'] ?? null),
            'form_home_ht_goals' => $this->val($context['form_home_ht_goals'] ?? null),
            'form_away_ht_goals' => $this->val($context['form_away_ht_goals'] ?? null),
            'h2h_home_ht_goals' => $this->val($context['h2h_home_ht_goals'] ?? null),
            'h2h_away_ht_goals' => $this->val($context['h2h_away_ht_goals'] ?? null),
            'shots_total' => $this->val($context['shots_total'] ?? null),
            'shots_on_target_total' => $this->val($context['shots_on_target_total'] ?? null),
            'dangerous_attacks_total' => $this->val($context['dangerous_attacks_total'] ?? null),
            'corners_total' => $this->val($context['corners_total'] ?? null),
        ];

        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        if ($isV2) {
            $template = preg_replace('/\{\{#if_legacy\}\}.*?\{\{\/if_legacy\}\}/s', '', $template) ?? $template;
            $template = str_replace(['{{#if_v2}}', '{{/if_v2}}'], '', $template);
        } else {
            $template = preg_replace('/\{\{#if_v2\}\}.*?\{\{\/if_v2\}\}/s', '', $template) ?? $template;
            $template = str_replace(['{{#if_legacy}}', '{{/if_legacy}}'], '', $template);
        }

        return $template;
    }

    /**
     * Call Gemini API with prompt.
     */
    private function callGeminiApi(string $prompt, string $mode): string
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => $this->getMaxOutputTokens($mode),
            ],
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize Gemini request');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Gemini cURL error: ' . $errNo . ' ' . $err);
        }

        if ($status >= 400) {
            throw new \RuntimeException('Gemini HTTP ' . $status . ': ' . $raw);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Gemini returned invalid JSON');
        }

        $response = $this->extractText($decoded);
        if ($response === null || trim($response) === '') {
            throw new \RuntimeException('Gemini returned empty analysis');
        }

        return trim($response);
    }

    private function getMaxOutputTokens(string $mode): int
    {
        return match ($this->normalizeMode($mode)) {
            self::MODE_CHANNEL_SHORT => 60,
            self::MODE_EXPLAIN => 350,
            default => 350,
        };
    }

    /**
     * Extract text from Gemini API response.
     *
     * @param array<string,mixed> $decoded
     */
    private function extractText(array $decoded): ?string
    {
        $candidates = $decoded['candidates'] ?? null;
        if (!is_array($candidates)) {
            return null;
        }

        $chunks = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $parts = $candidate['content']['parts'] ?? null;
            if (!is_array($parts)) {
                continue;
            }

            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $chunks[] = $part['text'];
                }
            }
        }

        if ($chunks === []) {
            return null;
        }

        return implode("\n", $chunks);
    }

    /**
     * Parse AI response into structured data.
     *
     * @return array<string,mixed>
     */
    private function parseResponse(string $response, string $mode = self::MODE_AUDIT): array
    {
        return match ($this->normalizeMode($mode)) {
            self::MODE_EXPLAIN => $this->parseExplainResponse($response),
            self::MODE_CHANNEL_SHORT => $this->parseShortResponse($response),
            default => $this->parseAuditResponse($response),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function parseAuditResponse(string $response): array
    {
        $decoded = json_decode($this->sanitizeResponse($response), true);

        if (!is_array($decoded)) {
            return $this->invalidResponseFallback($response, 'Gemini returned invalid JSON audit response');
        }

        $componentChecks = is_array($decoded['component_checks'] ?? null)
            ? $decoded['component_checks']
            : [];

        return [
            'verdict' => $this->normalizeVerdict($decoded['verdict'] ?? null),
            'confidence' => $this->normalizeConfidence($decoded['confidence'] ?? null),
            'summary' => $this->normalizeText($decoded['summary'] ?? null),
            'component_checks' => [
                'form' => $this->normalizeText($componentChecks['form'] ?? null),
                'h2h' => $this->normalizeText($componentChecks['h2h'] ?? null),
                'live' => $this->normalizeText($componentChecks['live'] ?? null),
                'gating' => $this->normalizeText($componentChecks['gating'] ?? null),
                'red_flags' => $this->normalizeText($componentChecks['red_flags'] ?? null),
            ],
            'key_factors' => $this->normalizeStringList($decoded['key_factors'] ?? null),
            'risks' => $this->normalizeStringList($decoded['risks'] ?? null),
            'contradictions' => $this->normalizeStringList($decoded['contradictions'] ?? null),
            'recommendation' => $this->normalizeText($decoded['recommendation'] ?? null),
            'parse_error' => null,
            'raw_response' => $response,
        ];
    }

    /**
     * @return array{text:string,parse_error:null|string,raw_response:string}
     */
    private function parseExplainResponse(string $response): array
    {
        $text = trim($this->sanitizeResponse($response));

        if ($text === '') {
            return [
                'text' => '',
                'parse_error' => 'Gemini returned empty explain response',
                'raw_response' => $response,
            ];
        }

        return [
            'text' => $text,
            'parse_error' => null,
            'raw_response' => $response,
        ];
    }

    /**
     * @return array{text:string,parse_error:null|string,raw_response:string}
     */
    private function parseShortResponse(string $response): array
    {
        $text = $this->normalizeShortText($response);

        if ($text === '') {
            return [
                'text' => '',
                'parse_error' => 'Gemini returned empty channel summary response',
                'raw_response' => $response,
            ];
        }

        return [
            'text' => $text,
            'parse_error' => null,
            'raw_response' => $response,
        ];
    }

    private function sanitizeResponse(string $response): string
    {
        $trimmed = trim($response);

        if (!str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $lines = preg_split("/\r\n|\n|\r/", $trimmed);
        if (!is_array($lines) || count($lines) < 3) {
            return $trimmed;
        }

        if (str_starts_with(trim((string) $lines[0]), '```')) {
            array_shift($lines);
        }

        $lastIndex = array_key_last($lines);
        if ($lastIndex !== null && trim((string) $lines[$lastIndex]) === '```') {
            unset($lines[$lastIndex]);
        }

        return trim(implode("\n", $lines));
    }

    private function normalizeShortText(string $response): string
    {
        $text = trim($this->sanitizeResponse($response));
        if ($text === '') {
            return '';
        }

        $firstLine = preg_split("/\r\n|\n|\r/", $text, 2);
        if (is_array($firstLine) && isset($firstLine[0])) {
            $text = $firstLine[0];
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B\"'`");

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || $words === []) {
            return '';
        }

        if (count($words) > 10) {
            $words = array_slice($words, 0, 10);
        }

        return trim(implode(' ', $words));
    }

    private function normalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        if (!in_array($normalized, self::ALLOWED_MODES, true)) {
            throw new \InvalidArgumentException('Unsupported GeminiAnalyzer mode: ' . $mode);
        }

        return $normalized;
    }

    /**
     * @return array{
     *   verdict:string,
     *   confidence:int,
     *   summary:string,
     *   component_checks:array{form:string,h2h:string,live:string,gating:string,red_flags:string},
     *   key_factors:array<int,string>,
     *   risks:array<int,string>,
     *   contradictions:array<int,string>,
     *   recommendation:string,
     *   parse_error:string,
     *   raw_response:string
     * }
     */
    private function invalidResponseFallback(string $response, string $error): array
    {
        return [
            'verdict' => 'unknown',
            'confidence' => 0,
            'summary' => '',
            'component_checks' => [
                'form' => '',
                'h2h' => '',
                'live' => '',
                'gating' => '',
                'red_flags' => '',
            ],
            'key_factors' => [],
            'risks' => [],
            'contradictions' => [],
            'recommendation' => '',
            'parse_error' => $error,
            'raw_response' => $response,
        ];
    }

    private function normalizeVerdict(mixed $value): string
    {
        if (!is_string($value)) {
            return 'unknown';
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, self::ALLOWED_VERDICTS, true)
            ? $normalized
            : 'unknown';
    }

    private function normalizeConfidence(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function normalizeText(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim($item);
            if ($item === '') {
                continue;
            }

            $normalized[] = $item;

            if (count($normalized) >= self::MAX_LIST_ITEMS) {
                break;
            }
        }

        return $normalized;
    }

    private function val(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::PLACEHOLDER;
        }

        if (is_bool($value)) {
            return $value ? self::BOOL_YES : self::BOOL_NO;
        }

        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? self::PLACEHOLDER : $encoded;
        }

        return (string) $value;
    }

    /**
     * @param array<mixed> $items
     */
    private function formatBulletList(array $items, string $emptyValue): string
    {
        if ($items === []) {
            return '- ' . $emptyValue;
        }

        $lines = [];
        foreach ($items as $item) {
            $lines[] = '- ' . $this->val($item);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $items
     */
    private function formatKeyValueList(array $items, string $emptyValue): string
    {
        if ($items === []) {
            return '- ' . $emptyValue;
        }

        $lines = [];
        $this->appendKeyValueLines($lines, $items);

        return implode("\n", $lines);
    }

    /**
     * @param array<int,string> $lines
     * @param array<string|int,mixed> $items
     */
    private function appendKeyValueLines(array &$lines, array $items, string $prefix = ''): void
    {
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $value !== []) {
                $this->appendKeyValueLines($lines, $value, $path);
                continue;
            }

            $lines[] = '- ' . $path . ': ' . $this->val($value);
        }
    }

    private function formatPercent(mixed $value): string
    {
        if (!is_numeric($value)) {
            return self::PLACEHOLDER;
        }

        $normalized = (float) $value;
        if ($normalized <= 1.0) {
            $normalized *= 100.0;
        }

        return number_format($normalized, 2, '.', '');
    }

    /**
     * @param array<string,mixed> $breakdown
     * @return array<string,mixed>
     */
    private function prepareProbabilityBreakdown(array $breakdown): array
    {
        $prepared = [];

        foreach (self::PROBABILITY_BREAKDOWN_KEYS as $key) {
            $prepared[$key] = $breakdown[$key] ?? null;
        }

        return $prepared;
    }

    /**
     * @param array<string,mixed> $thresholdEvaluation
     * @return array<string,mixed>
     */
    private function prepareThresholdEvaluation(array $thresholdEvaluation): array
    {
        return [
            'active' => $thresholdEvaluation['active'] ?? null,
            'candidates' => is_array($thresholdEvaluation['candidates'] ?? null)
                ? $thresholdEvaluation['candidates']
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $componentContributions
     * @return array<string,mixed>
     */
    private function prepareComponentContributions(array $componentContributions): array
    {
        $prepared = [];

        foreach (self::COMPONENT_CONTRIBUTION_KEYS as $key) {
            $prepared[$key] = $componentContributions[$key] ?? null;
        }

        return $prepared;
    }
}
