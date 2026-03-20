<?php

declare(strict_types=1);

namespace Proxbet\Telegram;

final class GeminiMatchAnalyzer
{
    public function __construct(
        private string $apiKey,
        private string $model = 'gemini-2.0-flash',
        private int $timeoutSeconds = 25
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @return array{provider:string,model:string,prompt:string,response:string}
     */
    public function analyze(array $context): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY is not configured');
        }

        $prompt = $this->buildPrompt($context);
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
                'temperature' => 0.4,
                'maxOutputTokens' => 500,
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

        $response = $this->alignResponseWithScanner(trim($response), $context);

        return [
            'provider' => 'gemini',
            'model' => $this->model,
            'prompt' => $prompt,
            'response' => $response,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function buildPrompt(array $context): string
    {
        $algorithmId = max(1, (int) ($context['algorithm_id'] ?? 1));
        if ($algorithmId === 3) {
            return $this->buildAlgorithmThreePrompt($context);
        }

        if ($algorithmId === 2) {
            return $this->buildAlgorithmTwoPrompt($context);
        }

        return $this->buildAlgorithmOnePrompt($context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function buildAlgorithmOnePrompt(array $context): string
    {
        $messageText = trim((string) ($context['message_text'] ?? ''));

        $lines = [
            'Ты футбольный аналитик для live-ставок. Нужен короткий, практичный и честный разбор матча.',
            '',
            'Не обещай гарантированный исход и не выдумывай факты.',
            'Работай строго на основе переданных данных.',
            '',
            'ОСНОВА АНАЛИЗА:',
            '- Главная опора - вероятность и вывод сканера проекта',
            '- Не пересчитывай вероятность матча с нуля',
            '- Используй вероятность сканера как базу для вердикта и уверенности',
            '',
            'ПРАВИЛА ОТКЛОНЕНИЯ:',
            '- Без сильного контраргумента не отклоняйся от вероятности сканера более чем на 10 п.п.',
            '- Уверенность должна быть близка к вероятности сканера (допустимо отклонение +/-5-10%)',
            '- Если сканер рекомендует ставку, не ставь "Не подходит" без явного противоречия данным',
            '',
            'ЛОГИКА ВЕРДИКТА:',
            'Если вероятность сканера:',
            '- 70% и выше -> обычно "Подходит"',
            '- 60-69% -> "Сомнительно"',
            '- ниже 60% -> "Не подходит"',
            '',
            'Отклонение возможно только при явных live-сигналах.',
            '',
            'ПРИОРИТЕТ LIVE-МЕТРИК (по убыванию важности):',
            '1. xG',
            '2. Удары в створ',
            '3. Опасные атаки',
            '4. Обычные атаки',
            '5. Угловые',
            '',
            'Не переоценивай количество атак без качества (моментов).',
            '',
            'УЧЕТ ВРЕМЕНИ МАТЧА:',
            '- После 30 минуты обращай больше внимания на качество моментов',
            '- После 35 минуты снижай уверенность при слабом давлении',
            '- После 40 минуты будь крайне осторожен с прогнозом гола в 1 тайме',
            '',
            'НЕГАТИВНЫЕ СИГНАЛЫ (сильные причины против ставки):',
            '- Суммарный xG низкий (например < 0.5 к 30 минуте)',
            '- Почти нет ударов в створ (0-1)',
            '- Слабые или редкие опасные атаки',
            '- Давление без реальных моментов',
            '',
            'Если такие сигналы есть - можешь понизить оценку или изменить вердикт.',
            '',
            'ОБЯЗАТЕЛЬНО:',
            '- Учитывай вердикт сканера',
            '- Если не согласен - кратко объясни почему',
            '- Не игнорируй данные сканера без причины',
            '',
            'ФОРМАТ ОТВЕТА (строго соблюдай):',
            '',
            'Вердикт: [Подходит / Сомнительно / Не подходит]',
            'Уверенность: [0-100]%',
            '',
            'Причины:',
            '- причина 1',
            '- причина 2',
            '- причина 3',
            '',
            'Риск:',
            '- 1 короткий пункт',
            '',
            'ДАННЫЕ ПО МАТЧУ:',
            'Матч: ' . $this->value($context['home'] ?? null) . ' vs ' . $this->value($context['away'] ?? null),
            'Лига: ' . $this->value($context['liga'] ?? null),
            'Страна: ' . $this->value($context['country'] ?? null),
            'Время: ' . $this->value($context['time'] ?? null),
            'Статус: ' . $this->value($context['match_status'] ?? null),
            'Счёт HT: ' . $this->score($context['live_ht_hscore'] ?? null, $context['live_ht_ascore'] ?? null),
            'Счёт live: ' . $this->score($context['live_hscore'] ?? null, $context['live_ascore'] ?? null),
            '',
            'Коэф. П1/X/П2: ' . $this->value($context['home_cf'] ?? null)
                . ' / ' . $this->value($context['draw_cf'] ?? null)
                . ' / ' . $this->value($context['away_cf'] ?? null),
            '',
            'Тотал:',
            'линия ' . $this->value($context['total_line'] ?? null),
            'ТБ ' . $this->value($context['total_line_tb'] ?? null),
            'ТМ ' . $this->value($context['total_line_tm'] ?? null),
            '',
            'Форма 1T:',
            'дома ' . $this->value($context['ht_match_goals_1'] ?? null),
            'гости ' . $this->value($context['ht_match_goals_2'] ?? null),
            '',
            'H2H 1T:',
            'дома ' . $this->value($context['h2h_ht_match_goals_1'] ?? null),
            'гости ' . $this->value($context['h2h_ht_match_goals_2'] ?? null),
            '',
            'LIVE:',
            'xG: ' . $this->value($context['live_xg_home'] ?? null) . ' / ' . $this->value($context['live_xg_away'] ?? null),
            'Атаки: ' . $this->value($context['live_att_home'] ?? null) . ' / ' . $this->value($context['live_att_away'] ?? null),
            'Опасные атаки: ' . $this->value($context['live_danger_att_home'] ?? null) . ' / ' . $this->value($context['live_danger_att_away'] ?? null),
            'Удары в створ: ' . $this->value($context['live_shots_on_target_home'] ?? null) . ' / ' . $this->value($context['live_shots_on_target_away'] ?? null),
            'Угловые: ' . $this->value($context['live_corner_home'] ?? null) . ' / ' . $this->value($context['live_corner_away'] ?? null),
            '',
            'ДАННЫЕ СКАНЕРА:',
            'Вероятность сканера: ' . $this->value($context['scanner_probability'] ?? null) . '%',
            'Form score: ' . $this->value($context['scanner_form_score'] ?? null),
            'H2H score: ' . $this->value($context['scanner_h2h_score'] ?? null),
            'Live score: ' . $this->value($context['scanner_live_score'] ?? null),
            '',
            'Сканер рекомендует ставку: ' . $this->value($context['scanner_bet'] ?? null),
            'Причина сканера: ' . $this->value($context['scanner_reason'] ?? null),
        ];

        if ($messageText !== '') {
            $lines[] = '';
            $lines[] = 'Изначальный сигнал бота:';
            $lines[] = $messageText;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function buildAlgorithmTwoPrompt(array $context): string
    {
        $messageText = trim((string) ($context['message_text'] ?? ''));
        $algorithmData = is_array($context['scanner_algorithm_data'] ?? null) ? $context['scanner_algorithm_data'] : [];
        $over25Text = !empty($algorithmData['over_25_odd_check_skipped'])
            ? 'проверка ТБ 2.5 пропущена, потому что тотал-линия уже выше 2.5'
            : $this->value($algorithmData['over_25_odd'] ?? null);

        $lines = [
            'Ты футбольный аналитик по live-ставкам.',
            'Нужен короткий, практичный и честный разбор под сценарий: фаворит-хозяева забьют в первом тайме.',
            '',
            'Не обещай гарантированный исход и не выдумывай факты.',
            'Работай строго по переданным данным.',
            '',
            'КОНТЕКСТ АЛГОРИТМА 2:',
            '- Это не вероятностная модель, а набор жестких фильтров сканера.',
            '- Базовая гипотеза: хозяева являются фаворитом и могут забить до перерыва при счете 0:0.',
            '- Не подменяй задачу прогнозом на гол любой команды. Оцени именно гол фаворита-хозяев в 1 тайме.',
            '',
            'ПРИОРИТЕТ АНАЛИЗА:',
            '1. Давление именно хозяев: xG, удары в створ, опасные атаки, угловые.',
            '2. Кто реально контролирует матч сейчас: хозяева или гости.',
            '3. Насколько live-картина подтверждает рыночный статус хозяев как фаворита.',
            '4. Подтверждают ли исторические фильтры алгоритма 2 текущий сценарий.',
            '',
            'НЕГАТИВНЫЕ СИГНАЛЫ:',
            '- Гости создают больше моментов, чем хозяева.',
            '- Есть активность, но она не в пользу фаворита.',
            '- У хозяев мало качества в атаках: низкий xG, мало ударов в створ, слабое давление.',
            '- Счет 0:0 держится без явного территориального или моментного перевеса хозяев.',
            '',
            'ЛОГИКА ВЕРДИКТА:',
            '- "Подходит" только если live-данные подтверждают, что именно хозяева ближе к голу до перерыва.',
            '- "Сомнительно" если фильтры алгоритма 2 выполнены, но текущий перевес хозяев неочевиден.',
            '- "Не подходит" если матч скорее против гола фаворита в первом тайме.',
            '',
            'ВАЖНО:',
            '- Учитывай вердикт сканера алгоритма 2 как базовый ориентир.',
            '- Если не согласен со сканером, объясни это через структуру live-игры.',
            '- Не называй гостей фаворитом, если коэффициент фаворита относится к хозяевам.',
            '',
            'ФОРМАТ ОТВЕТА (строго соблюдай):',
            '',
            'Вердикт: [Подходит / Сомнительно / Не подходит]',
            'Уверенность: [0-100]%',
            '',
            'Причины:',
            '- причина 1',
            '- причина 2',
            '- причина 3',
            '',
            'Риск:',
            '- 1 короткий пункт',
            '',
            'ДАННЫЕ ПО МАТЧУ:',
            'Алгоритм: ' . $this->value($context['algorithm_name'] ?? 'Алгоритм 2'),
            'Матч: ' . $this->value($context['home'] ?? null) . ' vs ' . $this->value($context['away'] ?? null),
            'Лига: ' . $this->value($context['liga'] ?? null),
            'Страна: ' . $this->value($context['country'] ?? null),
            'Время: ' . $this->value($context['time'] ?? null),
            'Статус: ' . $this->value($context['match_status'] ?? null),
            'Счёт HT: ' . $this->score($context['live_ht_hscore'] ?? null, $context['live_ht_ascore'] ?? null),
            'Счёт live: ' . $this->score($context['live_hscore'] ?? null, $context['live_ascore'] ?? null),
            '',
            'Коэф. П1/X/П2: ' . $this->value($context['home_cf'] ?? null)
                . ' / ' . $this->value($context['draw_cf'] ?? null)
                . ' / ' . $this->value($context['away_cf'] ?? null),
            'Тотал линия: ' . $this->value($context['total_line'] ?? null),
            'ТБ 2.5 для алгоритма 2: ' . $over25Text,
            '',
            'Фильтры алгоритма 2:',
            'Хозяева забивали в 1T за последние 5: ' . $this->value($algorithmData['home_first_half_goals_in_last_5'] ?? null),
            'H2H с голом любой команды в 1T за последние 5: ' . $this->value($algorithmData['h2h_first_half_goals_in_last_5'] ?? null),
            '',
            'Форма 1T:',
            'дома ' . $this->value($context['ht_match_goals_1'] ?? null),
            'гости ' . $this->value($context['ht_match_goals_2'] ?? null),
            '',
            'H2H 1T:',
            'дома ' . $this->value($context['h2h_ht_match_goals_1'] ?? null),
            'гости ' . $this->value($context['h2h_ht_match_goals_2'] ?? null),
            '',
            'LIVE:',
            'xG: ' . $this->value($context['live_xg_home'] ?? null) . ' / ' . $this->value($context['live_xg_away'] ?? null),
            'Атаки: ' . $this->value($context['live_att_home'] ?? null) . ' / ' . $this->value($context['live_att_away'] ?? null),
            'Опасные атаки: ' . $this->value($context['live_danger_att_home'] ?? null) . ' / ' . $this->value($context['live_danger_att_away'] ?? null),
            'Удары в створ: ' . $this->value($context['live_shots_on_target_home'] ?? null) . ' / ' . $this->value($context['live_shots_on_target_away'] ?? null),
            'Угловые: ' . $this->value($context['live_corner_home'] ?? null) . ' / ' . $this->value($context['live_corner_away'] ?? null),
            '',
            'ДАННЫЕ СКАНЕРА:',
            'Сканер рекомендует ставку: ' . $this->value($context['scanner_bet'] ?? null),
            'Причина сканера: ' . $this->value($context['scanner_reason'] ?? null),
        ];

        if ($messageText !== '') {
            $lines[] = '';
            $lines[] = 'Изначальный сигнал бота:';
            $lines[] = $messageText;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function buildAlgorithmThreePrompt(array $context): string
    {
        $messageText = trim((string) ($context['message_text'] ?? ''));
        $algorithmData = is_array($context['scanner_algorithm_data'] ?? null) ? $context['scanner_algorithm_data'] : [];

        $selectedTeamName = $this->value($algorithmData['selected_team_name'] ?? null);
        $selectedTeamBet = $this->value($algorithmData['selected_team_target_bet'] ?? null);
        $triggeredRule = $this->value($algorithmData['triggered_rule_label'] ?? ($algorithmData['triggered_rule'] ?? null));

        $lines = [
            'Ты футбольный аналитик по live-ставкам.',
            'Нужен короткий, практичный и честный разбор именно по ставке алгоритма 3.',
            '',
            'ВАЖНО:',
            '- Анализируй не матч в целом, а конкретную ставку на индивидуальный тотал команды больше 0.5.',
            '- Оцени, стоит ли поддержать ставку алгоритма 3 на выбранную команду.',
            '- Не выдумывай факты и не обещай гарантированный исход.',
            '- Работай только по переданным данным.',
            '',
            'СУТЬ АЛГОРИТМА 3:',
            '- Сигнал строится по табличной статистике команд.',
            '- Выбирается команда, у которой по таблице голов относительно игр больше, чем у соперника.',
            '- Ставка: индивидуальный тотал выбранной команды больше 0.5.',
            '- Сигнал публикуется только в перерыве и только если выбранная команда еще не забила.',
            '',
            'ТВОЯ ЗАДАЧА:',
            '- Дать мнение именно по ставке ' . $selectedTeamBet . '.',
            '- Связать табличный сигнал с текущим состоянием матча в перерыве.',
            '- Отдельно отметить сильные стороны и риски этой ставки.',
            '',
            'ФОРМАТ ОТВЕТА (строго соблюдай):',
            '',
            'Вердикт: [Поддерживаю / Сомневаюсь / Не поддерживаю]',
            'Уверенность: [0-100]%',
            '',
            'Причины:',
            '- причина 1',
            '- причина 2',
            '- причина 3',
            '',
            'Риск:',
            '- 1 короткий пункт',
            '',
            'ФОКУС СТАВКИ:',
            'Алгоритм: ' . $this->value($context['algorithm_name'] ?? 'Алгоритм 3'),
            'Тип сигнала: ' . $this->value($context['scanner_signal_type'] ?? 'team_total'),
            'Выбранная команда: ' . $selectedTeamName,
            'Сторона: ' . $this->value($algorithmData['selected_team_side'] ?? null),
            'Ставка: ' . $selectedTeamBet,
            'Сработавшее правило: ' . $triggeredRule,
            '',
            'ДАННЫЕ ПО МАТЧУ:',
            'Матч: ' . $this->value($context['home'] ?? null) . ' vs ' . $this->value($context['away'] ?? null),
            'Лига: ' . $this->value($context['liga'] ?? null),
            'Страна: ' . $this->value($context['country'] ?? null),
            'Время: ' . $this->value($context['time'] ?? null),
            'Статус: ' . $this->value($context['match_status'] ?? null),
            'Счет live: ' . $this->score($context['live_hscore'] ?? null, $context['live_ascore'] ?? null),
            '',
            'ТАБЛИЧНЫЕ МЕТРИКИ:',
            'Команда 1: игры ' . $this->value($algorithmData['table_games_1'] ?? null)
                . ', забито ' . $this->value($algorithmData['table_goals_1'] ?? null)
                . ', пропущено ' . $this->value($algorithmData['table_missed_1'] ?? null),
            'Команда 2: игры ' . $this->value($algorithmData['table_games_2'] ?? null)
                . ', забито ' . $this->value($algorithmData['table_goals_2'] ?? null)
                . ', пропущено ' . $this->value($algorithmData['table_missed_2'] ?? null),
            'Коэффициент атаки хозяев: ' . $this->value($algorithmData['home_attack_ratio'] ?? null),
            'Коэффициент обороны хозяев: ' . $this->value($algorithmData['home_defense_ratio'] ?? null),
            'Коэффициент атаки гостей: ' . $this->value($algorithmData['away_attack_ratio'] ?? null),
            'Коэффициент обороны гостей: ' . $this->value($algorithmData['away_defense_ratio'] ?? null),
            '',
            'LIVE:',
            'xG: ' . $this->value($context['live_xg_home'] ?? null) . ' / ' . $this->value($context['live_xg_away'] ?? null),
            'Атаки: ' . $this->value($context['live_att_home'] ?? null) . ' / ' . $this->value($context['live_att_away'] ?? null),
            'Опасные атаки: ' . $this->value($context['live_danger_att_home'] ?? null) . ' / ' . $this->value($context['live_danger_att_away'] ?? null),
            'Удары в створ: ' . $this->value($context['live_shots_on_target_home'] ?? null) . ' / ' . $this->value($context['live_shots_on_target_away'] ?? null),
            'Угловые: ' . $this->value($context['live_corner_home'] ?? null) . ' / ' . $this->value($context['live_corner_away'] ?? null),
            '',
            'ДАННЫЕ СКАНЕРА:',
            'Сканер рекомендует ставку: ' . $this->value($context['scanner_bet'] ?? null),
            'Причина сканера: ' . $this->value($context['scanner_reason'] ?? null),
            'Основа алгоритма: ' . $this->value($context['scanner_algorithm_basis'] ?? null),
        ];

        if ($messageText !== '') {
            $lines[] = '';
            $lines[] = 'Изначальный сигнал бота:';
            $lines[] = $messageText;
        }

        return implode("\n", $lines);
    }

    /**
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

    private function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'нет данных';
        }

        return (string) $value;
    }

    private function score(mixed $home, mixed $away): string
    {
        if ($home === null && $away === null) {
            return 'нет данных';
        }

        return (string) ($home ?? '?') . ':' . (string) ($away ?? '?');
    }

    /**
     * @param array<string,mixed> $context
     */
    private function alignResponseWithScanner(string $response, array $context): string
    {
        $algorithmId = max(1, (int) ($context['algorithm_id'] ?? 1));
        if ($algorithmId === 3) {
            $scannerReason = trim((string) ($context['scanner_reason'] ?? ''));
            $algorithmData = is_array($context['scanner_algorithm_data'] ?? null) ? $context['scanner_algorithm_data'] : [];
            $selectedTeam = trim((string) ($algorithmData['selected_team_name'] ?? ''));
            $targetBet = trim((string) ($algorithmData['selected_team_target_bet'] ?? ''));

            $syncNote = 'Синхронизация со сканером: ставка алгоритма 3';
            if ($targetBet !== '') {
                $syncNote .= ' ' . $targetBet;
            }
            if ($selectedTeam !== '') {
                $syncNote .= ' на команду ' . $selectedTeam;
            }
            if ($scannerReason !== '') {
                $syncNote .= ', причина: ' . $scannerReason;
            }

            if (!str_contains($response, 'Синхронизация со сканером:')) {
                $response .= "\n\n" . $syncNote;
            }

            return trim($response);
        }

        if ($algorithmId === 2) {
            $scannerReason = trim((string) ($context['scanner_reason'] ?? ''));
            if ($scannerReason !== '' && !str_contains($response, 'Синхронизация со сканером:')) {
                $response .= "\n\nСинхронизация со сканером: " . $scannerReason;
            }

            return trim($response);
        }

        $scannerProbability = $this->extractPercent($context['scanner_probability'] ?? null);
        if ($scannerProbability === null) {
            return $response;
        }

        $alignedConfidence = $scannerProbability;
        $parsedConfidence = $this->extractConfidenceFromResponse($response);
        if ($parsedConfidence !== null) {
            $weighted = (int) round($scannerProbability * 0.75 + $parsedConfidence * 0.25);
            $alignedConfidence = max($scannerProbability - 10, min($scannerProbability + 10, $weighted));
        }

        $response = preg_replace(
            '/^Уверенность:\s*\d{1,3}%/um',
            'Уверенность: ' . $alignedConfidence . '%',
            $response,
            1
        ) ?? $response;

        $scannerBet = trim((string) ($context['scanner_bet'] ?? ''));
        if ($scannerBet === 'yes') {
            $response = preg_replace(
                '/^Вердикт:\s*Не подходит$/um',
                'Вердикт: Сомнительно',
                $response,
                1
            ) ?? $response;
        }

        $scannerReason = trim((string) ($context['scanner_reason'] ?? ''));
        $syncNote = 'Синхронизация со сканером: базовая вероятность ' . $scannerProbability . '%';
        if ($scannerReason !== '') {
            $syncNote .= ', причина: ' . $scannerReason;
        }

        if (!str_contains($response, 'Синхронизация со сканером:')) {
            $response .= "\n\n" . $syncNote;
        }

        return trim($response);
    }

    private function extractConfidenceFromResponse(string $response): ?int
    {
        if (preg_match('/^Уверенность:\s*(\d{1,3})%/um', $response, $matches) === 1) {
            return max(0, min(100, (int) $matches[1]));
        }

        return null;
    }

    private function extractPercent(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = (int) round((float) $value);
            return max(0, min(100, $number));
        }

        return null;
    }
}
