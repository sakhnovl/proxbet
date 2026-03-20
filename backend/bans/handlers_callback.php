<?php

declare(strict_types=1);

use Proxbet\Line\Db;

function handleCallback(array $cq, array $ctx): void
{
    $cbId = (string) ($cq['id'] ?? '');
    $data = (string) ($cq['data'] ?? '');
    $fromId = (int) ($cq['from']['id'] ?? 0);
    $msg = $cq['message'] ?? null;
    $chatId = (int) ($msg['chat']['id'] ?? 0);
    $messageId = (int) ($msg['message_id'] ?? 0);

    if ($cbId === '' || $data === '' || $fromId === 0 || $chatId === 0 || $messageId === 0) {
        return;
    }

    $apiBase = (string) $ctx['apiBase'];
    $adminIds = (array) $ctx['adminIds'];

    if (!isAdmin($fromId, $adminIds)) {
        tgAnswerCallback($apiBase, $cbId, 'Недостаточно прав');
        return;
    }

    routeCallbackData($cbId, $data, $fromId, $chatId, $messageId, $ctx);
}

function routeCallbackData(string $cbId, string $data, int $fromId, int $chatId, int $messageId, array $ctx): void
{
    $apiBase = (string) $ctx['apiBase'];

    $parsed = parseBansCallbackData($data);
    if ($parsed === null) {
        tgAnswerCallback($apiBase, $cbId);
        return;
    }

    switch ($parsed['type']) {
        case 'help':
            onHelp($cbId, $chatId, $messageId, $ctx);
            return;

        case 'add':
            onAdd($cbId, $fromId, $chatId, $ctx);
            return;

        case 'menu':
            onMenu($cbId, $chatId, $messageId, $ctx);
            return;

        case 'wizard_cancel':
            onWizardCancel($cbId, $fromId, $chatId, $ctx);
            return;

        case 'list':
            onList($cbId, $chatId, $messageId, (int) ($parsed['offset'] ?? 0), $ctx);
            return;

        case 'edit':
            onEdit($cbId, $fromId, $chatId, (int) ($parsed['id'] ?? 0), $ctx);
            return;

        case 'edit_field':
            onEditField($cbId, $fromId, $chatId, (int) ($parsed['id'] ?? 0), (string) ($parsed['field'] ?? ''), $ctx);
            return;

        case 'del_ask':
            onDelAsk($cbId, $chatId, (int) ($parsed['id'] ?? 0), $ctx);
            return;

        case 'del_confirm':
            onDelConfirm($cbId, $fromId, $chatId, (int) ($parsed['id'] ?? 0), $ctx);
            return;

        case 'del_cancel':
            onDelCancel($cbId, $apiBase);
            return;
    }

    tgAnswerCallback($apiBase, $cbId);
}

function onHelp(string $cbId, int $chatId, int $messageId, array $ctx): void
{
    $apiBase = (string) $ctx['apiBase'];

    tgAnswerCallback($apiBase, $cbId);
    // parse_mode=HTML is used in tg_api.php by default, so we must not send raw "<...>".
    tgEditMessage($apiBase, $chatId, $messageId, "Команды:\n/bans\n/bans_add\n/bans_edit &lt;id&gt;\n/bans_del &lt;id&gt;", [
        'reply_markup' => json_encode(bansMenuKeyboard(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function onAdd(string $cbId, int $fromId, int $chatId, array $ctx): void
{
    $apiBase = (string) $ctx['apiBase'];
    $statePath = (string) $ctx['statePath'];
    $state =& $ctx['state'];

    tgAnswerCallback($apiBase, $cbId);

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
}

function onMenu(string $cbId, int $chatId, int $messageId, array $ctx): void
{
    $apiBase = (string) $ctx['apiBase'];

    tgAnswerCallback($apiBase, $cbId);
    tgEditMessage($apiBase, $chatId, $messageId, 'Меню банов:', [
        'reply_markup' => json_encode(bansMenuKeyboard(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function onWizardCancel(string $cbId, int $fromId, int $chatId, array $ctx): void
{
    $apiBase = (string) $ctx['apiBase'];
    $statePath = (string) $ctx['statePath'];
    $state =& $ctx['state'];

    tgAnswerCallback($apiBase, $cbId, 'Отменено');

    if (getUserWizard($state, $fromId) !== null) {
        clearUserWizard($state, $fromId);
        saveState($statePath, $state);
    }

    tgSendMessage($apiBase, $chatId, 'Ок. Возврат в меню банов:', [
        'reply_markup' => json_encode(bansMenuKeyboard(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function onList(string $cbId, int $chatId, int $messageId, int $offsetList, array $ctx): void
{
    $apiBase = (string) $ctx['apiBase'];
    $db = $ctx['db'];

    $pageSize = 10;
    $page = Db::listBans($db, $pageSize, $offsetList);
    $rows = $page['rows'] ?? [];
    $total = (int) ($page['total'] ?? 0);

    $out = "📃 Bans\n\n";
    foreach ($rows as $r) {
        $out .= formatBanRow($r) . "\n";
    }

    $buttons = [];
    $nav = [];

    if ($offsetList > 0) {
        $prev = max(0, $offsetList - $pageSize);
        $nav[] = ['text' => '⬅️ Prev', 'callback_data' => bansCbList($prev)];
    }
    if ($offsetList + $pageSize < $total) {
        $next = $offsetList + $pageSize;
        $nav[] = ['text' => 'Next ➡️', 'callback_data' => bansCbList($next)];
    }
    if ($nav !== []) {
        $buttons[] = $nav;
    }

    $buttons[] = [
        ['text' => '➕ Добавить', 'callback_data' => BANS_CB_ADD],
        ['text' => '🏠 Меню', 'callback_data' => BANS_CB_MENU],
    ];

    tgAnswerCallback($apiBase, $cbId);
    tgEditMessage($apiBase, $chatId, $messageId, $out . "\nВсего: {$total}", [
        'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        tgSendMessage($apiBase, $chatId, formatBanRow($r), [
            'reply_markup' => json_encode(banRowKeyboard($id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

function onEdit(string $cbId, int $fromId, int $chatId, int $banId, array $ctx): void
{
    $apiBase = (string) $ctx['apiBase'];
    $statePath = (string) $ctx['statePath'];
    $db = $ctx['db'];
    $state =& $ctx['state'];

    $row = Db::getBanById($db, $banId);
    if (!$row) {
        tgAnswerCallback($apiBase, $cbId, 'Ban не найден');
        return;
    }

    tgAnswerCallback($apiBase, $cbId);

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
}

function onEditField(string $cbId, int $fromId, int $chatId, int $banId, string $field, array $ctx): void
{
    $apiBase = (string) $ctx['apiBase'];
    $statePath = (string) $ctx['statePath'];
    $db = $ctx['db'];
    $state =& $ctx['state'];

    if (!in_array($field, ['country', 'liga', 'home', 'away'], true)) {
        tgAnswerCallback($apiBase, $cbId);
        return;
    }

    $row = Db::getBanById($db, $banId);
    if (!$row) {
        tgAnswerCallback($apiBase, $cbId, 'Ban не найден');
        return;
    }

    $wizard = getUserWizard($state, $fromId);
    if ($wizard === null || (string) ($wizard['mode'] ?? '') !== 'edit' || (int) ($wizard['ban_id'] ?? 0) !== $banId) {
        setUserWizard($state, $fromId, [
            'mode' => 'edit',
            'ban_id' => $banId,
            'step' => $field,
            'draft' => [
                'country' => $row['country'] ?? null,
                'liga' => $row['liga'] ?? null,
                'home' => $row['home'] ?? null,
                'away' => $row['away'] ?? null,
            ],
        ]);
    } else {
        $wizard['step'] = $field;
        $wizard['draft'] = is_array($wizard['draft'] ?? null) ? $wizard['draft'] : [];
        setUserWizard($state, $fromId, $wizard);
    }

    saveState($statePath, $state);
    tgAnswerCallback($apiBase, $cbId);

    $w = getUserWizard($state, $fromId);
    tgSendMessage($apiBase, $chatId, wizardPrompt('edit', $field, $w['draft'] ?? null), [
        'reply_markup' => json_encode(wizardCancelKeyboard(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function onDelAsk(string $cbId, int $chatId, int $banId, array $ctx): void
{
    $apiBase = (string) $ctx['apiBase'];

    tgAnswerCallback($apiBase, $cbId);
    tgSendMessage($apiBase, $chatId, "Подтвердите удаление ban #{$banId}:", [
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '✅ Да', 'callback_data' => bansCbDelConfirm($banId)],
                    ['text' => '❌ Нет', 'callback_data' => bansCbDelCancel($banId)],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function onDelConfirm(string $cbId, int $fromId, int $chatId, int $banId, array $ctx): void
{
    $apiBase = (string) $ctx['apiBase'];
    $db = $ctx['db'];

    $row = Db::getBanById($db, $banId);
    $ok = Db::deleteBan($db, $banId);

    Proxbet\Line\Logger::info('Ban deleted via telegram', [
        'tg_user_id' => $fromId,
        'ban_id' => $banId,
        'ok' => $ok,
        'prev' => $row,
    ]);

    tgAnswerCallback($apiBase, $cbId, $ok ? 'Удалено' : 'Не найден');
    tgSendMessage($apiBase, $chatId, $ok ? ("🗑 Удалено ban #{$banId}") : ("Ban #{$banId} не найден"), [
        'reply_markup' => json_encode(bansMenuKeyboard(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function onDelCancel(string $cbId, string $apiBase): void
{
    tgAnswerCallback($apiBase, $cbId, 'Отменено');
}
