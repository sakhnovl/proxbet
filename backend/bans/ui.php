<?php

declare(strict_types=1);

function bansMenuKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '📃 Список', 'callback_data' => bansCbList(0)],
                ['text' => '➕ Добавить', 'callback_data' => BANS_CB_ADD],
            ],
            [
                ['text' => 'ℹ️ Помощь', 'callback_data' => BANS_CB_HELP],
            ],
        ],
    ];
}

function formatBanRow(array $r): string
{
    $id = (int) ($r['id'] ?? 0);
    $country = (string) ($r['country'] ?? '');
    $liga = (string) ($r['liga'] ?? '');
    $home = (string) ($r['home'] ?? '');
    $away = (string) ($r['away'] ?? '');

    $esc = static fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return sprintf(
        '#%d | %s | %s | %s | %s',
        $id,
        $esc($country !== '' ? $country : '—'),
        $esc($liga !== '' ? $liga : '—'),
        $esc($home !== '' ? $home : '—'),
        $esc($away !== '' ? $away : '—')
    );
}

/** @return array<string,mixed> */
function banRowKeyboard(int $id): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '✏️ Edit', 'callback_data' => bansCbEdit($id)],
                ['text' => '🗑 Delete', 'callback_data' => bansCbDel($id)],
            ],
        ],
    ];
}

/** @return array<string,mixed> */
function editFieldKeyboard(int $banId): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Страна', 'callback_data' => bansCbEditField($banId, 'country')],
                ['text' => 'Лига', 'callback_data' => bansCbEditField($banId, 'liga')],
            ],
            [
                ['text' => 'Home', 'callback_data' => bansCbEditField($banId, 'home')],
                ['text' => 'Away', 'callback_data' => bansCbEditField($banId, 'away')],
            ],
            [
                ['text' => '❌ Отмена', 'callback_data' => BANS_CB_WIZ_CANCEL],
                ['text' => '🏠 Меню', 'callback_data' => BANS_CB_MENU],
            ],
        ],
    ];
}

/** @return array<string,mixed> */
function wizardCancelKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '❌ Отмена', 'callback_data' => BANS_CB_WIZ_CANCEL],
                ['text' => '🏠 Меню', 'callback_data' => BANS_CB_MENU],
            ],
        ],
    ];
}

function wizardPrompt(string $mode, string $step, ?array $current): string
{
    $title = $mode === 'add' ? '➕ Добавление бана' : '✏️ Редактирование бана';

    $fields = ['country' => 'Страна', 'liga' => 'Лига', 'home' => 'Home', 'away' => 'Away'];
    $label = $fields[$step] ?? $step;

    $hint = "Введите значение для <b>{$label}</b> (или '-' чтобы оставить пустым).";

    $cur = '';
    if (is_array($current)) {
        $cur .= "\n\nТекущее (черновик):\n";
        foreach (['country', 'liga', 'home', 'away'] as $f) {
            $v = $current[$f] ?? null;
            $v = is_string($v) && $v !== '' ? $v : '—';
            $cur .= sprintf("%s: %s\n", $fields[$f], htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
    }

    return $title . "\n" . $hint . $cur;
}
