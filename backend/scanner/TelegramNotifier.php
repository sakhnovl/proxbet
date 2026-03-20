<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Line\Logger;

require_once __DIR__ . '/../bans/tg_api.php';

/**
 * Sends scanner signals to Telegram with duplicate prevention.
 */
final class TelegramNotifier
{
    private string $apiBase;
    private string $channelId;
    private string $statePath;
    /** @var array<string,bool> */
    private array $sentMatches = [];
    private ?BetMessageRepository $repository;

    /**
     * @param string $channelId Telegram channel ID (e.g., -1003156000817)
     * @param BetMessageRepository|null $repository Repository for saving bet messages
     */
    public function __construct(string $token, string $channelId, string $statePath, ?BetMessageRepository $repository = null)
    {
        $this->apiBase = 'https://api.telegram.org/bot' . $token;
        $this->channelId = $channelId;
        $this->statePath = $statePath;
        $this->repository = $repository;
        $this->loadSentMatches();
    }

    /**
     * Send notification for a match signal.
     *
     * @param array<string,mixed> $match
     */
    public function notifySignal(array $match): void
    {
        $matchId = (int) ($match['match_id'] ?? 0);
        $key = $this->getMatchKey($match);

        if (isset($this->sentMatches[$key])) {
            Logger::info('Scanner notification skipped (already sent)', [
                'match_id' => $matchId,
                'key' => $key,
            ]);
            return;
        }

        $message = $this->formatMessage($match);

        $sent = false;
        $messageId = null;
        try {
            $resp = tgRequest($this->apiBase, 'sendMessage', [
                'chat_id' => $this->channelId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if ($resp['ok'] ?? false) {
                $sent = true;
                $messageId = (int) ($resp['result']['message_id'] ?? 0);
                Logger::info('Scanner notification sent to channel', [
                    'match_id' => $matchId,
                    'channel_id' => $this->channelId,
                    'message_id' => $messageId,
                ]);
            } else {
                Logger::error('Scanner notification failed', [
                    'match_id' => $matchId,
                    'channel_id' => $this->channelId,
                    'response' => $resp,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::error('Scanner notification exception', [
                'match_id' => $matchId,
                'channel_id' => $this->channelId,
                'error' => $e->getMessage(),
            ]);
        }

        if ($sent) {
            $this->sentMatches[$key] = true;
            $this->saveSentMatches();

            // Save message to database if repository is available
            if ($this->repository !== null && $messageId > 0) {
                try {
                    $this->repository->saveBetMessage(
                        $matchId,
                        $messageId,
                        $this->channelId,
                        $message
                    );
                } catch (\Throwable $e) {
                    Logger::error('Failed to save bet message to database', [
                        'match_id' => $matchId,
                        'message_id' => $messageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Generate unique key for match to prevent duplicates.
     * Each match is sent only once, regardless of minute changes.
     *
     * @param array<string,mixed> $match
     */
    private function getMatchKey(array $match): string
    {
        $matchId = (int) ($match['match_id'] ?? 0);

        return sprintf('match_%d', $matchId);
    }

    /**
     * Format match data as Telegram message.
     *
     * @param array<string,mixed> $match
     */
    private function formatMessage(array $match): string
    {
        $probability = sprintf('%.0f%%', ($match['probability'] ?? 0) * 100);
        $home = htmlspecialchars((string) ($match['home'] ?? ''), ENT_QUOTES, 'UTF-8');
        $away = htmlspecialchars((string) ($match['away'] ?? ''), ENT_QUOTES, 'UTF-8');
        $liga = htmlspecialchars((string) ($match['liga'] ?? ''), ENT_QUOTES, 'UTF-8');
        $time = htmlspecialchars((string) ($match['time'] ?? ''), ENT_QUOTES, 'UTF-8');

        $scoreHome = (int) ($match['score_home'] ?? 0);
        $scoreAway = (int) ($match['score_away'] ?? 0);
        $score = sprintf('%d:%d', $scoreHome, $scoreAway);

        $formScore = sprintf('%.2f', $match['form_score'] ?? 0);
        $h2hScore = sprintf('%.2f', $match['h2h_score'] ?? 0);
        $liveScore = sprintf('%.2f', $match['live_score'] ?? 0);

        $stats = $match['stats'] ?? [];
        $shotsTotal = (int) ($stats['shots_total'] ?? 0);
        $shotsOnTarget = (int) ($stats['shots_on_target'] ?? 0);
        $dangerAttacks = (int) ($stats['dangerous_attacks'] ?? 0);
        $corners = (int) ($stats['corners'] ?? 0);

        $formData = $match['form_data'] ?? [];
        $homeFormGoals = (int) ($formData['home_goals'] ?? 0);
        $awayFormGoals = (int) ($formData['away_goals'] ?? 0);

        $h2hData = $match['h2h_data'] ?? [];
        $homeH2hGoals = (int) ($h2hData['home_goals'] ?? 0);
        $awayH2hGoals = (int) ($h2hData['away_goals'] ?? 0);

        $reason = htmlspecialchars((string) ($match['decision']['reason'] ?? ''), ENT_QUOTES, 'UTF-8');

        return "🔥 <b>СИГНАЛ: ГОЛ В ПЕРВОМ ТАЙМЕ</b>\n\n"
            . "⚽ <b>{$home} - {$away}</b>\n"
            . "🏆 {$liga}\n"
            . "⏱ Время: <b>{$time}</b>\n"
            . "⚽ Счет: <b>{$score}</b>\n\n"
            . "📊 <b>Вероятность: {$probability}</b>\n"
            . "├ Форма: {$formScore} (40%)\n"
            . "├ H2H: {$h2hScore} (20%)\n"
            . "└ Live: {$liveScore} (40%)\n\n"
            . "📈 <b>Статистика матча:</b>\n"
            . "├ Удары: {$shotsTotal} (в створ: {$shotsOnTarget})\n"
            . "├ Опасные атаки: {$dangerAttacks}\n"
            . "└ Угловые: {$corners}\n\n"
            . "📋 <b>Форма 1T:</b> дома {$homeFormGoals}/5, гости {$awayFormGoals}/5\n"
            . "🤝 <b>H2H 1T:</b> дома {$homeH2hGoals}/5, гости {$awayH2hGoals}/5\n\n"
            . "✅ {$reason}";
    }

    /**
     * Load sent matches from state file.
     */
    private function loadSentMatches(): void
    {
        if (!file_exists($this->statePath)) {
            $this->sentMatches = [];
            return;
        }

        $content = file_get_contents($this->statePath);
        if ($content === false) {
            $this->sentMatches = [];
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['sent_matches']) || !is_array($data['sent_matches'])) {
            $this->sentMatches = [];
            return;
        }

        $this->sentMatches = $this->normalizeSentMatches($data['sent_matches']);
        $this->cleanOldEntries();
    }

    /**
     * Save sent matches to state file.
     */
    private function saveSentMatches(): void
    {
        $data = ['sent_matches' => $this->sentMatches];
        $json = json_encode($data, JSON_PRETTY_PRINT);

        if ($json === false) {
            Logger::error('Failed to encode scanner state');
            return;
        }

        $result = file_put_contents($this->statePath, $json);
        if ($result === false) {
            Logger::error('Failed to save scanner state', ['path' => $this->statePath]);
        }
    }

    /**
     * Remove entries older than 2 hours to prevent state file growth.
     */
    private function cleanOldEntries(): void
    {
        if (count($this->sentMatches) > 1000) {
            $this->sentMatches = array_slice($this->sentMatches, -500, null, true);
        }
    }

    /**
     * @param array<string,mixed> $sentMatches
     * @return array<string,bool>
     */
    private function normalizeSentMatches(array $sentMatches): array
    {
        $normalized = [];

        foreach ($sentMatches as $key => $value) {
            if (!$value || !is_string($key)) {
                continue;
            }

            if (preg_match('/^match_(\d+)(?:_min_\d+)?$/', $key, $matches) === 1) {
                $normalized['match_' . $matches[1]] = true;
                continue;
            }

            $normalized[$key] = true;
        }

        return $normalized;
    }
}
