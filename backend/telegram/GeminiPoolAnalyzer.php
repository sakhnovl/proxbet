<?php

declare(strict_types=1);

namespace Proxbet\Telegram;

final class GeminiPoolAnalyzer
{
    public function __construct(
        private TelegramAiRepository $repository,
        private int $timeoutSeconds = 25
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @return array{provider:string,model:string,prompt:string,response:string,key_id:int,model_id:int}
     */
    public function analyze(array $context): array
    {
        $keys = $this->repository->listActiveGeminiKeys();
        if ($keys === []) {
            throw new \RuntimeException('No active Gemini API keys configured in database');
        }

        $models = $this->repository->listActiveGeminiModels();
        if ($models === []) {
            throw new \RuntimeException('No active Gemini models configured in database');
        }

        $errors = [];

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
                    $analysis = $analyzer->analyze($context);

                    $this->repository->markGeminiModelSuccess($modelId);
                    $this->repository->markGeminiKeySuccess($keyId);

                    $analysis['key_id'] = $keyId;
                    $analysis['model_id'] = $modelId;

                    return $analysis;
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    $keyErrors[] = sprintf('[model:%s] %s', $modelName, $message);
                    $errors[] = sprintf('[key:%d model:%s] %s', $keyId, $modelName, $message);
                    $this->repository->markGeminiModelFailure($modelId, $message);
                }
            }

            if ($keyErrors !== []) {
                $this->repository->markGeminiKeyFailure($keyId, implode(' | ', $keyErrors));
            }
        }

        throw new \RuntimeException('All Gemini key/model combinations failed: ' . implode(' || ', $errors));
    }
}
