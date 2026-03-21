<?php

declare(strict_types=1);

namespace Proxbet\Telegram;

use PDO;
use Proxbet\Line\Logger;
use Proxbet\Security\Encryption;

require_once __DIR__ . '/../line/logger.php';
require_once __DIR__ . '/../security/Encryption.php';

final class TelegramAiRepository
{
    private const NEW_USER_TRIAL_BALANCE = 5;

    private ?Encryption $encryption = null;

    public function __construct(private PDO $pdo)
    {
        // Initialize encryption if available
        try {
            $this->encryption = Encryption::fromEnv();
        } catch (\Throwable $e) {
            // Encryption not configured - will work with plain text
            Logger::info('Encryption not configured for API keys', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $telegramUser
     * @return array<string,mixed>
     */
    public function upsertTelegramUser(array $telegramUser): array
    {
        $telegramUserId = (int) ($telegramUser['id'] ?? 0);
        if ($telegramUserId <= 0) {
            throw new \InvalidArgumentException('Telegram user ID is required');
        }

        $username = $this->normalizeText($telegramUser['username'] ?? null);
        $firstName = $this->normalizeText($telegramUser['first_name'] ?? null);
        $lastName = $this->normalizeText($telegramUser['last_name'] ?? null);

        $stmt = $this->pdo->prepare(
            'INSERT INTO `telegram_users` (`telegram_user_id`, `username`, `first_name`, `last_name`, `last_interaction_at`) '
            . 'VALUES (?, ?, ?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . '`username` = VALUES(`username`), '
            . '`first_name` = VALUES(`first_name`), '
            . '`last_name` = VALUES(`last_name`), '
            . '`last_interaction_at` = NOW()'
        );
        $stmt->execute([$telegramUserId, $username, $firstName, $lastName]);

        $isNewUser = (int) $stmt->rowCount() === 1;
        if ($isNewUser) {
            $trialStmt = $this->pdo->prepare(
                'UPDATE `telegram_users` SET `ai_balance` = ? WHERE `telegram_user_id` = ?'
            );
            $trialStmt->execute([self::NEW_USER_TRIAL_BALANCE, $telegramUserId]);

            Logger::info('Granted new user trial balance', [
                'telegram_user_id' => $telegramUserId,
                'credits' => self::NEW_USER_TRIAL_BALANCE,
            ]);
        }

        $user = $this->getTelegramUser($telegramUserId);
        if ($user === null) {
            throw new \RuntimeException('Failed to load telegram user after upsert');
        }

        $user['is_new_user'] = $isNewUser;
        $user['trial_balance_granted'] = $isNewUser ? self::NEW_USER_TRIAL_BALANCE : 0;

        return $user;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getTelegramUser(int $telegramUserId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT `telegram_user_id`, `username`, `first_name`, `last_name`, `ai_balance` '
            . 'FROM `telegram_users` WHERE `telegram_user_id` = ? LIMIT 1'
        );
        $stmt->execute([$telegramUserId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array{allowed:bool,charged:int,balance:int}
     */
    public function consumeAnalysisAccess(int $telegramUserId, int $cost): array
    {
        $cost = max(0, $cost);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT `ai_balance` '
                . 'FROM `telegram_users` '
                . 'WHERE `telegram_user_id` = ? '
                . 'FOR UPDATE'
            );
            $stmt->execute([$telegramUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \RuntimeException('Telegram user not found for access check');
            }

            $balance = (int) ($row['ai_balance'] ?? 0);
            $charged = 0;

            if ($balance < $cost) {
                $this->pdo->commit();
                return [
                    'allowed' => false,
                    'charged' => 0,
                    'balance' => $balance,
                ];
            }

            if ($cost > 0) {
                $newBalance = $balance - $cost;
                $update = $this->pdo->prepare(
                    'UPDATE `telegram_users` SET `ai_balance` = ? WHERE `telegram_user_id` = ?'
                );
                $update->execute([$newBalance, $telegramUserId]);
                $balance = $newBalance;
                $charged = $cost;
            }

            $this->pdo->commit();

            return [
                'allowed' => true,
                'charged' => $charged,
                'balance' => $balance,
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function refundBalance(int $telegramUserId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE `telegram_users` SET `ai_balance` = `ai_balance` + ? WHERE `telegram_user_id` = ?'
        );
        $stmt->execute([$amount, $telegramUserId]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getAnalysisRequest(int $telegramUserId, int $matchId, ?int $betMessageId = null): ?array
    {
        $sql = 'SELECT `telegram_user_id`, `match_id`, `bet_message_id`, `provider`, `model_name`, `status`, '
            . '`cost_charged`, `prompt_text`, `response_text`, `error_text`, `updated_at` '
            . 'FROM `ai_analysis_requests` '
            . 'WHERE `telegram_user_id` = ? AND `match_id` = ?';
        $params = [$telegramUserId, $matchId];

        if ($betMessageId !== null) {
            $sql .= ' AND `bet_message_id` = ?';
            $params[] = $betMessageId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function savePendingAnalysis(
        int $telegramUserId,
        int $matchId,
        ?int $betMessageId,
        string $provider,
        string $model,
        string $prompt,
        int $costCharged
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `ai_analysis_requests` '
            . '(`telegram_user_id`, `match_id`, `bet_message_id`, `provider`, `model_name`, `status`, `cost_charged`, `prompt_text`) '
            . 'VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?) '
            . 'ON DUPLICATE KEY UPDATE '
            . '`bet_message_id` = VALUES(`bet_message_id`), '
            . '`provider` = VALUES(`provider`), '
            . '`model_name` = VALUES(`model_name`), '
            . '`status` = \'pending\', '
            . '`cost_charged` = VALUES(`cost_charged`), '
            . '`prompt_text` = VALUES(`prompt_text`), '
            . '`response_text` = NULL, '
            . '`error_text` = NULL'
        );
        $stmt->execute([$telegramUserId, $matchId, $betMessageId, $provider, $model, $costCharged, $prompt]);
    }

    public function saveCompletedAnalysis(int $telegramUserId, int $matchId, string $response): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `ai_analysis_requests` '
            . 'SET `status` = \'completed\', `response_text` = ?, `error_text` = NULL '
            . 'WHERE `telegram_user_id` = ? AND `match_id` = ?'
        );
        $stmt->execute([$response, $telegramUserId, $matchId]);
    }

    public function saveFailedAnalysis(int $telegramUserId, int $matchId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `ai_analysis_requests` '
            . 'SET `status` = \'failed\', `error_text` = ?, `response_text` = NULL '
            . 'WHERE `telegram_user_id` = ? AND `match_id` = ?'
        );
        $stmt->execute([$error, $telegramUserId, $matchId]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listActiveGeminiKeys(): array
    {
        $stmt = $this->pdo->query(
            'SELECT `id`, `api_key`, `is_active`, `is_encrypted`, `last_error`, `fail_count`, `last_used_at` '
            . 'FROM `gemini_api_keys` '
            . 'WHERE `is_active` = 1 '
            . 'ORDER BY `id` ASC'
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        // Decrypt API keys if encrypted
        foreach ($rows as &$row) {
            $row['api_key'] = $this->decryptApiKey($row['api_key'], (bool) ($row['is_encrypted'] ?? false));
            unset($row['is_encrypted']); // Don't expose encryption status
        }

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listGeminiKeys(): array
    {
        $stmt = $this->pdo->query(
            'SELECT `id`, `api_key`, `is_active`, `is_encrypted`, `last_error`, `fail_count`, `last_used_at`, `created_at` '
            . 'FROM `gemini_api_keys` ORDER BY `id` ASC'
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        // Decrypt API keys if encrypted
        foreach ($rows as &$row) {
            $row['api_key'] = $this->decryptApiKey($row['api_key'], (bool) ($row['is_encrypted'] ?? false));
            unset($row['is_encrypted']); // Don't expose encryption status
        }

        return $rows;
    }

    public function addGeminiKey(string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Gemini API key must not be empty');
        }

        // Encrypt the API key if encryption is available
        $encryptedKey = $this->encryptApiKey($apiKey);
        $isEncrypted = $this->encryption !== null ? 1 : 0;

        $stmt = $this->pdo->prepare(
            'INSERT INTO `gemini_api_keys` (`api_key`, `is_active`, `is_encrypted`) VALUES (?, 1, ?) '
            . 'ON DUPLICATE KEY UPDATE `is_active` = 1, `is_encrypted` = VALUES(`is_encrypted`)'
        );
        $stmt->execute([$encryptedKey, $isEncrypted]);
    }

    public function setGeminiKeyActive(int $id, bool $isActive): void
    {
        $stmt = $this->pdo->prepare('UPDATE `gemini_api_keys` SET `is_active` = ? WHERE `id` = ?');
        $stmt->execute([$isActive ? 1 : 0, $id]);
    }

    public function markGeminiKeySuccess(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `gemini_api_keys` SET `last_error` = NULL, `fail_count` = 0, `last_used_at` = NOW() WHERE `id` = ?'
        );
        $stmt->execute([$id]);
    }

    public function markGeminiKeyFailure(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `gemini_api_keys` '
            . 'SET `last_error` = ?, `fail_count` = `fail_count` + 1, `last_used_at` = NOW() '
            . 'WHERE `id` = ?'
        );
        $stmt->execute([$error, $id]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listActiveGeminiModels(): array
    {
        $stmt = $this->pdo->query(
            'SELECT `id`, `model_name`, `is_active`, `last_error`, `fail_count`, `last_used_at` '
            . 'FROM `gemini_models` '
            . 'WHERE `is_active` = 1 '
            . 'ORDER BY `id` ASC'
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listGeminiModels(): array
    {
        $stmt = $this->pdo->query(
            'SELECT `id`, `model_name`, `is_active`, `last_error`, `fail_count`, `last_used_at`, `created_at` '
            . 'FROM `gemini_models` ORDER BY `id` ASC'
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function addGeminiModel(string $modelName): void
    {
        $modelName = trim($modelName);
        if ($modelName === '') {
            throw new \InvalidArgumentException('Gemini model must not be empty');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO `gemini_models` (`model_name`, `is_active`) VALUES (?, 1) '
            . 'ON DUPLICATE KEY UPDATE `is_active` = 1'
        );
        $stmt->execute([$modelName]);
    }

    public function setGeminiModelActive(int $id, bool $isActive): void
    {
        $stmt = $this->pdo->prepare('UPDATE `gemini_models` SET `is_active` = ? WHERE `id` = ?');
        $stmt->execute([$isActive ? 1 : 0, $id]);
    }

    public function markGeminiModelSuccess(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `gemini_models` SET `last_error` = NULL, `fail_count` = 0, `last_used_at` = NOW() WHERE `id` = ?'
        );
        $stmt->execute([$id]);
    }

    public function markGeminiModelFailure(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `gemini_models` '
            . 'SET `last_error` = ?, `fail_count` = `fail_count` + 1, `last_used_at` = NOW() '
            . 'WHERE `id` = ?'
        );
        $stmt->execute([$error, $id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getAnalysisContext(int $matchId, ?int $algorithmId = null): ?array
    {
        $betMessageSubquery = 'SELECT `id` FROM `bet_messages` WHERE `match_id` = m.`id`';
        $params = [];
        if ($algorithmId !== null) {
            $betMessageSubquery .= ' AND `algorithm_id` = ?';
            $params[] = $algorithmId;
        }
        $betMessageSubquery .= ' ORDER BY `sent_at` DESC LIMIT 1';

        $stmt = $this->pdo->prepare(
            'SELECT '
            . 'm.`id` AS `match_id`, m.`country`, m.`liga`, m.`home`, m.`away`, m.`time`, m.`match_status`, '
            . 'm.`start_time`, m.`sgi_json`, m.`live_ht_hscore`, m.`live_ht_ascore`, m.`live_hscore`, m.`live_ascore`, '
            . 'm.`home_cf`, m.`draw_cf`, m.`away_cf`, m.`total_line`, m.`total_line_tb`, m.`total_line_tm`, '
            . 'm.`ht_match_goals_1`, m.`ht_match_goals_2`, m.`h2h_ht_match_goals_1`, m.`h2h_ht_match_goals_2`, '
            . 'm.`table_games_1`, m.`table_goals_1`, m.`table_missed_1`, '
            . 'm.`table_games_2`, m.`table_goals_2`, m.`table_missed_2`, '
            . 'm.`live_xg_home`, m.`live_xg_away`, m.`live_att_home`, m.`live_att_away`, '
            . 'm.`live_danger_att_home`, m.`live_danger_att_away`, '
            . 'm.`live_shots_on_target_home`, m.`live_shots_on_target_away`, '
            . 'm.`live_shots_off_target_home`, m.`live_shots_off_target_away`, '
            . 'm.`live_corner_home`, m.`live_corner_away`, '
            . 'bm.`id` AS `bet_message_id`, bm.`algorithm_id`, bm.`algorithm_name`, bm.`message_text`, '
            . 'bm.`algorithm_payload_json`, bm.`sent_at` AS `bet_sent_at` '
            . 'FROM `matches` m '
            . 'LEFT JOIN `bet_messages` bm ON bm.`id` = (' . $betMessageSubquery . ') '
            . 'WHERE m.`id` = ? '
            . 'LIMIT 1'
        );
        $params[] = $matchId;
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function grantBalance(int $telegramUserId, int $amount): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Balance amount must be positive');
        }

        $this->ensureTelegramUserExists($telegramUserId);

        $stmt = $this->pdo->prepare(
            'UPDATE `telegram_users` SET `ai_balance` = `ai_balance` + ? WHERE `telegram_user_id` = ?'
        );
        $stmt->execute([$amount, $telegramUserId]);

        $user = $this->getTelegramUser($telegramUserId);
        if ($user === null) {
            throw new \RuntimeException('Telegram user not found after balance grant');
        }

        return $user;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function ensureTelegramUserExists(int $telegramUserId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `telegram_users` (`telegram_user_id`, `last_interaction_at`) '
            . 'VALUES (?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE `last_interaction_at` = COALESCE(`last_interaction_at`, NOW())'
        );
        $stmt->execute([$telegramUserId]);
    }

    /**
     * Encrypt API key if encryption is available
     */
    private function encryptApiKey(string $apiKey): string
    {
        if ($this->encryption === null) {
            return $apiKey;
        }

        try {
            return $this->encryption->encrypt($apiKey);
        } catch (\Throwable $e) {
            Logger::info('Failed to encrypt API key, storing as plain text', ['error' => $e->getMessage()]);
            return $apiKey;
        }
    }

    /**
     * Decrypt API key if it's encrypted
     */
    private function decryptApiKey(string $apiKey, bool $isEncrypted): string
    {
        if (!$isEncrypted || $this->encryption === null) {
            return $apiKey;
        }

        try {
            return $this->encryption->decrypt($apiKey);
        } catch (\Throwable $e) {
            Logger::info('Failed to decrypt API key', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to decrypt API key - encryption key may have changed');
        }
    }
}
