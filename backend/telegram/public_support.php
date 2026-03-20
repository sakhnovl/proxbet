<?php

declare(strict_types=1);

function analysisCallbackData(int $matchId, int $algorithmId = 1): string
{
    return 'ai:match:' . max(0, $matchId) . ':alg:' . max(1, $algorithmId);
}

function parseAnalysisCallbackData(string $data): ?array
{
    if (preg_match('/^ai:match:(\d+):alg:(\d+)$/', $data, $matches) === 1) {
        return [
            'match_id' => (int) $matches[1],
            'algorithm_id' => max(1, (int) $matches[2]),
        ];
    }

    if (preg_match('/^ai:match:(\d+)$/', $data, $matches) === 1) {
        return [
            'match_id' => (int) $matches[1],
            'algorithm_id' => 1,
        ];
    }

    return null;
}

function getAnalysisButtonText(): string
{
    $text = trim((string) (getenv('TELEGRAM_AI_BUTTON_TEXT') ?: '🤖 Анализ Gemini'));
    return $text !== '' ? $text : '🤖 Анализ Gemini';
}

function getCreditsTopUpUrl(): string
{
    $raw = trim((string) (getenv('TELEGRAM_CREDITS_TOPUP_URL') ?: getenv('TELEGRAM_SUBSCRIPTION_URL') ?: ''));
    if ($raw === '') {
        return '';
    }

    if (preg_match('/^@?([A-Za-z][A-Za-z0-9_]{3,31})$/', $raw, $matches) === 1) {
        return 'https://t.me/' . $matches[1];
    }

    return $raw;
}

function getAnalysisCost(): int
{
    return max(1, (int) (getenv('AI_ANALYSIS_COST') ?: 1));
}
