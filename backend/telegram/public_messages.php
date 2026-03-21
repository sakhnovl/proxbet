<?php

declare(strict_types=1);

require_once __DIR__ . '/../bans/tg_api.php';

function buildStartMessage(array $user, array $adminIds, int $telegramUserId): string
{
    $text = "Добро пожаловать в <b>Клон Бет</b>.\n";

    if ((bool) ($user['is_new_user'] ?? false)) {
        $trialCredits = (int) ($user['trial_balance_granted'] ?? 0);
        $text .= "\n🎁 <b>Приветственный бонус</b>\n"
            . "Мы начислили вам <b>{$trialCredits} кредитов</b> для теста AI-анализа.\n"
            . "Попробуйте любой матч прямо сейчас.\n";
    }

    $text .= "\n" . buildBalanceMessage($user);

    if (in_array($telegramUserId, $adminIds, true)) {
        $text .= "\n\nАдмин-команды:\nНиже кнопки для копирования команд.";
    }

    return $text;
}

function buildBalanceMessage(array $user): string
{
    $balance = (int) ($user['ai_balance'] ?? 0);
    $analysisCost = getAnalysisCost();
    $balanceWord = formatCreditsWord($balance);
    $costWord = formatCreditsWord($analysisCost);
    $status = getWalletStatusMeta($balance, $analysisCost);

    return "💼 <b>Ваш AI-кошелёк</b>\n"
        . "├ Статус: {$status['emoji']} <b>{$status['label']}</b>\n"
        . "├ Баланс: <b>{$balance}</b> {$balanceWord}\n"
        . "└ Стоимость 1 AI-анализа: <b>{$analysisCost}</b> {$costWord}";
}

function buildBuyMessage(): string
{
    if (getCreditsTopUpUrl() !== '') {
        return "Кредиты закончились.\nЧтобы продолжить получать AI-разборы матчей, пополните баланс по кнопке ниже.";
    }

    return "Кредиты закончились.\nАвтопополнение еще не подключено. Свяжитесь с администратором для начисления кредитов.";
}

function buildAnalysisDeliveryMessage(string $analysisText, array $user): string
{
    return "🤖 <b>AI-анализ матча</b>\n\n" . $analysisText . "\n\n" . buildBalanceMessage($user);
}

function formatCreditsWord(int $amount): string
{
    $mod100 = $amount % 100;
    $mod10 = $amount % 10;

    if ($mod100 >= 11 && $mod100 <= 14) {
        return 'кредитов';
    }

    return match ($mod10) {
        1 => 'кредит',
        2, 3, 4 => 'кредита',
        default => 'кредитов',
    };
}

/**
 * @return array{emoji:string,label:string}
 */
function getWalletStatusMeta(int $balance, int $analysisCost): array
{
    if ($balance < $analysisCost) {
        return ['emoji' => '🔴', 'label' => 'нужно пополнение'];
    }

    if ($balance <= $analysisCost * 3) {
        return ['emoji' => '🟡', 'label' => 'кредитов немного'];
    }

    return ['emoji' => '🟢', 'label' => 'готов к анализам'];
}

function creditMessageOptions(): array
{
    $rows = buildCreditButtonRows();
    if ($rows === []) {
        return [];
    }

    return [
        'reply_markup' => json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function buildStartMessageOptions(array $adminIds, int $telegramUserId): array
{
    $rows = [];

    if (in_array($telegramUserId, $adminIds, true)) {
        $rows = array_merge($rows, buildAdminCopyCommandRows());
    }

    $rows = array_merge($rows, buildCreditButtonRows());

    if ($rows === []) {
        return [];
    }

    return [
        'reply_markup' => json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function buildAdminCopyCommandRows(): array
{
    return [
        [
            copyTextButton('Список ключей', '/gemini_keys'),
            copyTextButton('Список моделей', '/gemini_models'),
        ],
        [
            copyTextButton('Добавить ключ', '/gemini_add_key '),
            copyTextButton('Добавить модель', '/gemini_add_model '),
        ],
        [
            copyTextButton('Пополнить баланс', '/add_credits '),
        ],
    ];
}

function buildCreditButtonRows(): array
{
    $url = getCreditsTopUpUrl();
    if ($url === '') {
        return [];
    }

    return [
        [
            [
                'text' => 'Пополнить баланс',
                'url' => $url,
            ],
        ],
    ];
}

function copyTextButton(string $label, string $text): array
{
    return [
        'text' => $label,
        'copy_text' => ['text' => $text],
    ];
}

function deliverPrivateMessage(string $apiBase, int $telegramUserId, string $text, bool $withTopUpButton = false): bool
{
    try {
        tgSendMessage($apiBase, $telegramUserId, $text, $withTopUpButton ? creditMessageOptions() : []);
        return true;
    } catch (\Throwable) {
        return false;
    }
}

function formatGeminiKeysList(array $rows): string
{
    if ($rows === []) {
        return 'Ключи Gemini не найдены.';
    }

    $lines = ['Ключи Gemini:'];
    foreach ($rows as $row) {
        $masked = maskSecret((string) ($row['api_key'] ?? ''));
        $lines[] = sprintf(
            '- #%d %s [%s]',
            (int) ($row['id'] ?? 0),
            $masked,
            !empty($row['is_active']) ? 'active' : 'inactive'
        );
    }

    return implode("\n", $lines);
}

function formatGeminiModelsList(array $rows): string
{
    if ($rows === []) {
        return 'Модели Gemini не найдены.';
    }

    $lines = ['Модели Gemini:'];
    foreach ($rows as $row) {
        $lines[] = sprintf(
            '- #%d %s [%s]',
            (int) ($row['id'] ?? 0),
            (string) ($row['model_name'] ?? ''),
            !empty($row['is_active']) ? 'active' : 'inactive'
        );
    }

    return implode("\n", $lines);
}

function maskSecret(string $value): string
{
    $length = strlen($value);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 4) . str_repeat('*', max(0, $length - 8)) . substr($value, -4);
}
