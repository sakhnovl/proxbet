<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

require_once __DIR__ . '/../line/logger.php';
require_once __DIR__ . '/../bans/tg_api.php';

use Proxbet\Line\Logger;

/**
 * Service for checking bet outcomes and updating Telegram messages.
 */
final class BetChecker
{
    private string $apiBase;

    public function __construct(
        private BetMessageRepository $repository,
        string $botToken
    ) {
        $this->apiBase = 'https://api.telegram.org/bot' . $botToken;
    }

    /**
     * Check all pending bets and update their status.
     *
     * @return array{checked:int,won:int,lost:int,pending:int,errors:int}
     */
    public function checkPendingBets(): array
    {
        $pendingBets = $this->repository->getPendingBets();
        
        $checked = 0;
        $won = 0;
        $lost = 0;
        $stillPending = 0;
        $errors = 0;

        Logger::info('Bet checker started', ['pending_bets' => count($pendingBets)]);

        foreach ($pendingBets as $bet) {
            $checked++;
            
            try {
                $outcome = $this->checkBetOutcome($bet);

                if ($outcome === 'won' || $outcome === 'lost') {
                    // Update Telegram message
                    $updated = $this->updateTelegramMessage($bet, $outcome);
                    
                    if ($updated) {
                        // Update database status
                        $this->repository->updateBetStatus((int) $bet['bet_id'], $outcome);
                        
                        if ($outcome === 'won') {
                            $won++;
                        } else {
                            $lost++;
                        }

                        Logger::info('Bet outcome determined', [
                            'bet_id' => $bet['bet_id'],
                            'match_id' => $bet['match_id'],
                            'outcome' => $outcome,
                        ]);
                    } else {
                        $errors++;
                    }
                } else {
                    $stillPending++;
                }
            } catch (\Throwable $e) {
                $errors++;
                Logger::error('Failed to check bet', [
                    'bet_id' => $bet['bet_id'] ?? null,
                    'match_id' => $bet['match_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Logger::info('Bet checker completed', [
            'checked' => $checked,
            'won' => $won,
            'lost' => $lost,
            'still_pending' => $stillPending,
            'errors' => $errors,
        ]);

        return [
            'checked' => $checked,
            'won' => $won,
            'lost' => $lost,
            'pending' => $stillPending,
            'errors' => $errors,
        ];
    }

    /**
     * Determine bet outcome based on match data.
     *
     * @param array<string,mixed> $bet
     * @return string 'won', 'lost', or 'pending'
     */
    private function checkBetOutcome(array $bet): string
    {
        $time = (string) ($bet['time'] ?? '');
        $status = (string) ($bet['match_status'] ?? '');
        $htHome = (int) ($bet['live_ht_hscore'] ?? 0);
        $htAway = (int) ($bet['live_ht_ascore'] ?? 0);
        $totalGoals = $htHome + $htAway;

        // Parse time in format "mm:ss"
        $minute = $this->parseMatchTime($time);

        // Check for won bet: goal scored before or at 45 minutes
        if ($totalGoals > 0) {
            // Goal scored during first half
            if ($status === '1-й тайм' && $minute <= 45) {
                return 'won';
            }
            
            // Goal scored at 45 minutes and halftime started
            if ($minute >= 45 && $status === 'Перерыв') {
                return 'won';
            }
        }

        // Check for lost bet: 0:0 at halftime
        if ($totalGoals === 0 && $minute >= 45 && $status === 'Перерыв') {
            return 'lost';
        }

        // Still pending - match not finished first half yet
        return 'pending';
    }

    /**
     * Parse match time from "mm:ss" format to minutes.
     *
     * @param string $time Time in format "mm:ss" or "m:ss"
     * @return int Minutes
     */
    private function parseMatchTime(string $time): int
    {
        if ($time === '' || $time === null) {
            return 0;
        }

        $parts = explode(':', $time);
        if (count($parts) !== 2) {
            return 0;
        }

        return (int) $parts[0];
    }

    /**
     * Update Telegram message with bet result.
     *
     * @param array<string,mixed> $bet
     * @param string $outcome 'won' or 'lost'
     * @return bool Success
     */
    private function updateTelegramMessage(array $bet, string $outcome): bool
    {
        $messageId = (int) ($bet['message_id'] ?? 0);
        $chatId = (string) ($bet['chat_id'] ?? '');
        $originalText = (string) ($bet['message_text'] ?? '');

        if ($messageId === 0 || $chatId === '') {
            Logger::error('Invalid message data for update', [
                'bet_id' => $bet['bet_id'] ?? null,
                'message_id' => $messageId,
                'chat_id' => $chatId,
            ]);
            return false;
        }

        $updatedText = $this->formatResultMessage($bet, $originalText, $outcome);

        try {
            $resp = tgRequest($this->apiBase, 'editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $updatedText,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if ($resp['ok'] ?? false) {
                Logger::info('Telegram message updated', [
                    'bet_id' => $bet['bet_id'],
                    'message_id' => $messageId,
                    'outcome' => $outcome,
                ]);
                return true;
            } else {
                Logger::error('Failed to update Telegram message', [
                    'bet_id' => $bet['bet_id'],
                    'message_id' => $messageId,
                    'response' => $resp,
                ]);
                return false;
            }
        } catch (\Throwable $e) {
            Logger::error('Exception updating Telegram message', [
                'bet_id' => $bet['bet_id'],
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Format message with bet result.
     *
     * @param array<string,mixed> $bet
     * @param string $originalText
     * @param string $outcome 'won' or 'lost'
     * @return string
     */
    private function formatResultMessage(array $bet, string $originalText, string $outcome): string
    {
        $htHome = (int) ($bet['live_ht_hscore'] ?? 0);
        $htAway = (int) ($bet['live_ht_ascore'] ?? 0);
        $score = sprintf('%d:%d', $htHome, $htAway);
        $time = htmlspecialchars((string) ($bet['time'] ?? ''), ENT_QUOTES, 'UTF-8');

        $resultBlock = "\n\n━━━━━━━━━━━━━━━━━━━━\n";
        
        if ($outcome === 'won') {
            $resultBlock .= "✅ <b>СТАВКА ЗАШЛА!</b>\n";
        } else {
            $resultBlock .= "❌ <b>СТАВКА НЕ ЗАШЛА</b>\n";
        }
        
        $resultBlock .= "⚽ Счет 1T: <b>{$score}</b>\n";
        $resultBlock .= "⏱ Время: <b>{$time}</b>";

        return $originalText . $resultBlock;
    }
}
