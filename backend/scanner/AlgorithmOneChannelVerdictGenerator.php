<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Line\Logger;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\GeminiAnalyzer;
use Proxbet\Telegram\TelegramAiRepository;

final class AlgorithmOneChannelVerdictGenerator
{
    public function __construct(
        private TelegramAiRepository $repository,
        private int $timeoutSeconds = 15
    ) {
    }

    /**
     * @param array<string,mixed> $match
     */
    public function generate(array $match): ?string
    {
        if (max(1, (int) ($match['algorithm_id'] ?? 1)) !== 1) {
            return null;
        }

        $context = $this->buildContext($match);
        $keys = $this->repository->listActiveGeminiKeys();
        $models = $this->repository->listActiveGeminiModels();

        if ($keys === [] || $models === []) {
            return null;
        }

        foreach ($keys as $keyRow) {
            $keyId = (int) ($keyRow['id'] ?? 0);
            $apiKey = trim((string) ($keyRow['api_key'] ?? ''));
            if ($keyId <= 0 || $apiKey === '') {
                continue;
            }

            $keyErrors = [];

            foreach ($models as $modelRow) {
                $modelId = (int) ($modelRow['id'] ?? 0);
                $modelName = trim((string) ($modelRow['model_name'] ?? ''));
                if ($modelId <= 0 || $modelName === '') {
                    continue;
                }

                try {
                    $analyzer = new GeminiAnalyzer($apiKey, $modelName, $this->timeoutSeconds);
                    $result = $analyzer->analyze($context, GeminiAnalyzer::MODE_CHANNEL_SHORT);
                    $analysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];
                    $text = trim((string) ($analysis['text'] ?? ''));

                    if ($text === '') {
                        throw new \RuntimeException('Gemini returned empty channel summary response');
                    }

                    $this->repository->markGeminiModelSuccess($modelId);
                    $this->repository->markGeminiKeySuccess($keyId);

                    return $text;
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    $keyErrors[] = sprintf('[model:%s] %s', $modelName, $message);
                    $this->repository->markGeminiModelFailure($modelId, $message);
                    Logger::info('AlgorithmOne channel verdict generation failed for model', [
                        'match_id' => (int) ($match['match_id'] ?? 0),
                        'key_id' => $keyId,
                        'model_id' => $modelId,
                        'error' => $message,
                    ]);
                }
            }

            if ($keyErrors !== []) {
                $this->repository->markGeminiKeyFailure($keyId, implode(' | ', $keyErrors));
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $match
     * @return array<string,mixed>
     */
    private function buildContext(array $match): array
    {
        $algorithmData = is_array($match['algorithm_data'] ?? null) ? $match['algorithm_data'] : [];
        $components = is_array($algorithmData['components'] ?? null) ? $algorithmData['components'] : [];
        $probabilityBreakdown = is_array($components['probability_breakdown'] ?? null)
            ? $components['probability_breakdown']
            : [];
        $stats = is_array($match['stats'] ?? null) ? $match['stats'] : [];
        $formData = is_array($match['form_data'] ?? null) ? $match['form_data'] : [];
        $h2hData = is_array($match['h2h_data'] ?? null) ? $match['h2h_data'] : [];

        return [
            'algorithm_version' => (int) ($algorithmData['algorithm_version'] ?? 1),
            'home' => $match['home'] ?? null,
            'away' => $match['away'] ?? null,
            'league' => $match['liga'] ?? null,
            'country' => $match['country'] ?? null,
            'time' => $match['time'] ?? null,
            'minute' => $match['minute'] ?? null,
            'live_hscore' => $match['score_home'] ?? null,
            'live_ascore' => $match['score_away'] ?? null,
            'bet' => (bool) ($match['decision']['bet'] ?? false),
            'probability' => $algorithmData['probability'] ?? ($match['probability'] ?? null),
            'reason' => $algorithmData['decision_reason'] ?? ($match['decision']['reason'] ?? null),
            'components' => $this->buildComponents($components, $probabilityBreakdown, $match),
            'gating_passed' => $algorithmData['gating_passed'] ?? null,
            'gating_reason' => $algorithmData['gating_reason'] ?? null,
            'red_flags' => $algorithmData['red_flags'] ?? [],
            'penalties' => $algorithmData['penalties'] ?? [],
            'gating_context' => $algorithmData['gating_context'] ?? [],
            'shots_total' => $stats['shots_total'] ?? null,
            'shots_on_target_total' => $stats['shots_on_target'] ?? null,
            'dangerous_attacks_total' => $stats['dangerous_attacks'] ?? null,
            'corners_total' => $stats['corners'] ?? null,
            'form_home_ht_goals' => $formData['home_goals'] ?? null,
            'form_away_ht_goals' => $formData['away_goals'] ?? null,
            'h2h_home_ht_goals' => $h2hData['home_goals'] ?? null,
            'h2h_away_ht_goals' => $h2hData['away_goals'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $components
     * @param array<string,mixed> $probabilityBreakdown
     * @param array<string,mixed> $match
     * @return array<string,mixed>
     */
    private function buildComponents(array $components, array $probabilityBreakdown, array $match): array
    {
        if ($components === []) {
            return [
                'form_score' => $match['form_score'] ?? null,
                'h2h_score' => $match['h2h_score'] ?? null,
                'live_score' => $match['live_score'] ?? null,
            ];
        }

        if (!array_key_exists('form_score', $components) && array_key_exists('form_score', $probabilityBreakdown)) {
            $components['form_score'] = $probabilityBreakdown['form_score'];
        }

        if (!array_key_exists('h2h_score', $components) && array_key_exists('h2h_score', $probabilityBreakdown)) {
            $components['h2h_score'] = $probabilityBreakdown['h2h_score'];
        }

        if (!array_key_exists('live_score', $components)) {
            if (array_key_exists('live_score_adjusted', $probabilityBreakdown)) {
                $components['live_score'] = $probabilityBreakdown['live_score_adjusted'];
            } elseif (array_key_exists('live_score_base', $probabilityBreakdown)) {
                $components['live_score'] = $probabilityBreakdown['live_score_base'];
            }
        }

        return $components;
    }
}
