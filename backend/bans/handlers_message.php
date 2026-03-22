<?php

declare(strict_types=1);

use Proxbet\Line\Db;
use Proxbet\Telegram\TelegramAiRepository;

function handleMessage(array $msg, array $ctx): void
{
    $chatId = (int) ($msg['chat']['id'] ?? 0);
    $fromId = (int) ($msg['from']['id'] ?? 0);
    $text = $msg['text'] ?? null;

    if ($chatId === 0 || $fromId === 0 || !is_string($text)) {
        return;
    }

    $apiBase = (string) $ctx['apiBase'];
    $adminIds = (array) $ctx['adminIds'];
    $from = is_array($msg['from'] ?? null) ? $msg['from'] : [];
    $textTrim = trim($text);

    if (tryHandlePublicCommand($from, $chatId, $textTrim, $ctx)) {
        return;
    }

    if (!isAdmin($fromId, $adminIds)) {
        tgSendMessage($apiBase, $chatId, 'Недостаточно прав.');
        return;
    }

    if (tryHandleWizardStep($fromId, $chatId, $textTrim, $ctx)) {
        return;
    }

    if (tryHandleCommand($fromId, $chatId, $textTrim, $ctx)) {
        return;
    }

    tgSendMessage($apiBase, $chatId, 'Не понял команду. /bans');
}

/** @return bool true if handled as wizard step */
function tryHandleWizardStep(int $fromId, int $chatId, string $textTrim, array $ctx): bool
{
    $state =& $ctx['state'];

    $wizard = getUserWizard($state, $fromId);
    if ($wizard === null || !isset($wizard['mode'], $wizard['step'])) {
        return false;
    }

    $apiBase = (string) $ctx['apiBase'];
    $statePath = (string) $ctx['statePath'];
    $db = $ctx['db'];

    $mode = (string) $wizard['mode'];
    $step = (string) $wizard['step'];

    if (!in_array($step, ['country', 'liga', 'home', 'away'], true)) {
        return false;
    }

    $draft = is_array($wizard['draft'] ?? null) ? $wizard['draft'] : [];

    $input = normalizeWizardInput($textTrim);
    $maxLen = $step === 'country' ? 128 : 255;
    $draft[$step] = cleanField($input, $maxLen);

    if ($mode === 'add') {
        $steps = ['country', 'liga', 'home', 'away'];
        $idx = array_search($step, $steps, true);
        $next = $idx === false ? null : ($steps[$idx + 1] ?? null);

        if ($next !== null) {
            $wizard['step'] = $next;
            $wizard['draft'] = $draft;
            setUserWizard($state, $fromId, $wizard);
            saveState($statePath, $state);

            tgSendMessage($apiBase, $chatId, wizardPrompt($mode, $next, $draft), [
                'reply_markup' => json_encode(wizardCancelKeyboard(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return true;
        }

        $newId = Db::addBan($db, [
            'country' => $draft['country'] ?? null,
            'liga' => $draft['liga'] ?? null,
            'home' => $draft['home'] ?? null,
            'away' => $draft['away'] ?? null,
        ]);

        Proxbet\Line\Logger::info('Ban added via telegram', [
            'tg_user_id' => $fromId,
            'ban_id' => $newId,
            'data' => $draft,
        ]);

        clearUserWizard($state, $fromId);
        saveState($statePath, $state);

        $row = Db::getBanById($db, $newId);
        $textOut = "Ban added\n" . ($row ? formatBanRow($row) : ('#' . $newId));
        tgSendMessage($apiBase, $chatId, $textOut, [
            'reply_markup' => json_encode(bansMenuKeyboard(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return true;
    }

    if ($mode === 'edit') {
        $banId = (int) ($wizard['ban_id'] ?? 0);
        if ($banId <= 0) {
            clearUserWizard($state, $fromId);
            saveState($statePath, $state);
            tgSendMessage($apiBase, $chatId, 'Ошибка состояния редактирования. Начните заново: /bans');
            return true;
        }

        $ok = Db::updateBan($db, $banId, [
            'country' => $draft['country'] ?? null,
            'liga' => $draft['liga'] ?? null,
            'home' => $draft['home'] ?? null,
            'away' => $draft['away'] ?? null,
        ]);

        Proxbet\Line\Logger::info('Ban edited via telegram', [
            'tg_user_id' => $fromId,
            'ban_id' => $banId,
            'ok' => $ok,
            'field' => $step,
            'value' => $draft[$step] ?? null,
        ]);

        clearUserWizard($state, $fromId);
        saveState($statePath, $state);

        $row = Db::getBanById($db, $banId);
        $textOut = "Ban updated\n" . ($row ? formatBanRow($row) : ('#' . $banId));
        tgSendMessage($apiBase, $chatId, $textOut, [
            'reply_markup' => json_encode(bansMenuKeyboard(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return true;
    }

    return false;
}

/** @return bool true if command handled */
function tryHandleCommand(int $fromId, int $chatId, string $textTrim, array $ctx): bool
{
    $apiBase = (string) $ctx['apiBase'];
    $statePath = (string) $ctx['statePath'];
    $db = $ctx['db'];
    $state =& $ctx['state'];
    $repository = new TelegramAiRepository($db);

    if (preg_match('/^\/grant_balance\s+(\d+)\s+(\d+)$/', $textTrim, $m)) {
        $targetTelegramUserId = (int) $m[1];
        $grantedAmount = (int) $m[2];
        $user = $repository->grantBalance($targetTelegramUserId, $grantedAmount);

        deliverPrivateMessage(
            $apiBase,
            $targetTelegramUserId,
            "Вам начислено <b>{$grantedAmount}</b> кредитов.\n\n" . buildBalanceMessage($user),
            false
        );

        tgSendMessage($apiBase, $chatId, "Баланс обновлён.\n" . buildBalanceMessage($user));
        return true;
    }

    if (preg_match('/^\/gemini_key_add\s+(.+)$/', $textTrim, $m)) {
        $repository->addGeminiKey(trim($m[1]));
        tgSendMessage($apiBase, $chatId, 'Gemini API key сохранён в базе.');
        return true;
    }

    if ($textTrim === '/gemini_key_list') {
        tgSendMessage($apiBase, $chatId, formatGeminiKeysList($repository->listGeminiKeys()));
        return true;
    }

    if (preg_match('/^\/gemini_key_on\s+(\d+)$/', $textTrim, $m)) {
        $repository->setGeminiKeyActive((int) $m[1], true);
        tgSendMessage($apiBase, $chatId, "Gemini key #{$m[1]} включён.");
        return true;
    }

    if (preg_match('/^\/gemini_key_off\s+(\d+)$/', $textTrim, $m)) {
        $repository->setGeminiKeyActive((int) $m[1], false);
        tgSendMessage($apiBase, $chatId, "Gemini key #{$m[1]} выключен.");
        return true;
    }

    if (preg_match('/^\/gemini_model_add\s+([A-Za-z0-9._:-]+)$/', $textTrim, $m)) {
        $repository->addGeminiModel(trim($m[1]));
        tgSendMessage($apiBase, $chatId, 'Gemini model сохранена в базе.');
        return true;
    }

    if ($textTrim === '/gemini_model_list') {
        tgSendMessage($apiBase, $chatId, formatGeminiModelsList($repository->listGeminiModels()));
        return true;
    }

    if (preg_match('/^\/gemini_model_on\s+(\d+)$/', $textTrim, $m)) {
        $repository->setGeminiModelActive((int) $m[1], true);
        tgSendMessage($apiBase, $chatId, "Gemini model #{$m[1]} включена.");
        return true;
    }

    if (preg_match('/^\/gemini_model_off\s+(\d+)$/', $textTrim, $m)) {
        $repository->setGeminiModelActive((int) $m[1], false);
        tgSendMessage($apiBase, $chatId, "Gemini model #{$m[1]} выключена.");
        return true;
    }

    if (str_starts_with($textTrim, '/start')) {
        tgSendMessage($apiBase, $chatId, "Команды:\n/bans — управление банами");
        return true;
    }

    if ($textTrim === '/bans') {
        tgSendMessage($apiBase, $chatId, 'Меню банов:', [
            'reply_markup' => json_encode(bansMenuKeyboard(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return true;
    }

    if (str_starts_with($textTrim, '/bans_list')) {
        $page = Db::listBans($db, 10, 0);
        $rows = $page['rows'];
        $out = "Bans (top 10)\n\n";
        foreach ($rows as $r) {
            $out .= formatBanRow($r) . "\n";
        }
        tgSendMessage($apiBase, $chatId, $out);
        return true;
    }

    if ($textTrim === '/bans_add') {
        setUserWizard($state, $fromId, [
            'mode' => 'add',
            'step' => 'country',
            'draft' => ['country' => null, 'liga' => null, 'home' => null, 'away' => null],
        ]);
        saveState($statePath, $state);

        $wizard = getUserWizard($state, $fromId);
        tgSendMessage($apiBase, $chatId, wizardPrompt('add', 'country', $wizard['draft'] ?? null), [
            'reply_markup' => json_encode(wizardCancelKeyboard(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return true;
    }

    if (preg_match('/^\/bans_edit\s+(\d+)/', $textTrim, $m)) {
        $banId = (int) $m[1];
        $row = Db::getBanById($db, $banId);
        if (!$row) {
            tgSendMessage($apiBase, $chatId, 'Не найден ban id=' . $banId);
            return true;
        }

        setUserWizard($state, $fromId, [
            'mode' => 'edit',
            'ban_id' => $banId,
            'step' => 'choose',
            'draft' => [
                'country' => $row['country'] ?? null,
                'liga' => $row['liga'] ?? null,
                'home' => $row['home'] ?? null,
                'away' => $row['away'] ?? null,
            ],
        ]);
        saveState($statePath, $state);

        tgSendMessage($apiBase, $chatId, "Что изменить в ban #{$banId}?\n" . formatBanRow($row), [
            'reply_markup' => json_encode(editFieldKeyboard($banId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return true;
    }

    if (preg_match('/^\/bans_del\s+(\d+)/', $textTrim, $m)) {
        $banId = (int) $m[1];
        tgSendMessage($apiBase, $chatId, "Подтвердите удаление ban #{$banId}:", [
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => 'Да', 'callback_data' => bansCbDelConfirm($banId)],
                        ['text' => 'Нет', 'callback_data' => bansCbDelCancel($banId)],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return true;
    }

    return false;
}
