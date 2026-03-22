<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Line\Logger;
use Proxbet\Telegram\GeminiMatchAnalyzer;
use Proxbet\Telegram\TelegramAiRepository;

final class AlgorithmXChannelVerdictGenerator
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
        if (max(1, (int) ($match['algorithm_id'] ?? 1)) !== 4) {
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
                    $analyzer = new GeminiMatchAnalyzer($apiKey, $modelName, $this->timeoutSeconds);
                    $result = $analyzer->analyze($context, 'channel_short');

                    $this->repository->markGeminiModelSuccess($modelId);
                    $this->repository->markGeminiKeySuccess($keyId);

                    return trim($result['response']);
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    $keyErrors[] = sprintf('[model:%s] %s', $modelName, $message);
                    $this->repository->markGeminiModelFailure($modelId, $message);
                    Logger::info('AlgorithmX channel verdict generation failed for model', [
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

        return [
            'algorithm_id' => 4,
            'home' => $match['home'] ?? null,
            'away' => $match['away'] ?? null,
            'liga' => $match['liga'] ?? null,
            'country' => $match['country'] ?? null,
            'time' => $match['time'] ?? null,
            'match_status' => $match['match_status'] ?? null,
            'live_ht_hscore' => $match['score_home'] ?? null,
            'live_ht_ascore' => $match['score_away'] ?? null,
            'live_hscore' => $match['score_home'] ?? null,
            'live_ascore' => $match['score_away'] ?? null,
            'scanner_bet' => !empty($match['decision']['bet']) ? 'yes' : 'no',
            'scanner_reason' => $match['decision']['reason'] ?? null,
            'scanner_probability' => isset($match['probability']) ? (float) $match['probability'] * 100 : null,
            'scanner_algorithm_data' => $algorithmData,
        ];
    }
}
