<?php

declare(strict_types=1);

// Callback data constants for bans bot.

const BANS_CB_HELP = 'bans:help';
const BANS_CB_ADD = 'bans:add';
const BANS_CB_MENU = 'bans:menu';
const BANS_CB_WIZ_CANCEL = 'bans:wizard_cancel';

/** Build callback_data for list paging. */
function bansCbList(int $offset): string
{
    return 'bans:list:' . max(0, $offset);
}

/** Build callback_data for edit action. */
function bansCbEdit(int $banId): string
{
    return 'bans:edit:' . max(0, $banId);
}

/** Build callback_data for edit field action. */
function bansCbEditField(int $banId, string $field): string
{
    return 'bans:edit_field:' . max(0, $banId) . ':' . $field;
}

/** Build callback_data for delete ask action. */
function bansCbDel(int $banId): string
{
    return 'bans:del:' . max(0, $banId);
}

/** Build callback_data for delete confirm action. */
function bansCbDelConfirm(int $banId): string
{
    return 'bans:del_confirm:' . max(0, $banId);
}

/** Build callback_data for delete cancel action. */
function bansCbDelCancel(int $banId): string
{
    return 'bans:del_cancel:' . max(0, $banId);
}

/**
 * Parse bans callback_data.
 *
 * @return array{type: string, id?: int, offset?: int, field?: 'country'|'liga'|'home'|'away'}|null
 */
function parseBansCallbackData(string $data): ?array
{
    if ($data === BANS_CB_HELP) {
        return ['type' => 'help'];
    }
    if ($data === BANS_CB_ADD) {
        return ['type' => 'add'];
    }
    if ($data === BANS_CB_MENU) {
        return ['type' => 'menu'];
    }
    if ($data === BANS_CB_WIZ_CANCEL) {
        return ['type' => 'wizard_cancel'];
    }

    if (preg_match('/^bans:list:(\d+)$/', $data, $m)) {
        return ['type' => 'list', 'offset' => (int) $m[1]];
    }
    if (preg_match('/^bans:edit:(\d+)$/', $data, $m)) {
        return ['type' => 'edit', 'id' => (int) $m[1]];
    }
    if (preg_match('/^bans:edit_field:(\d+):(country|liga|home|away)$/', $data, $m)) {
        return ['type' => 'edit_field', 'id' => (int) $m[1], 'field' => $m[2]];
    }
    if (preg_match('/^bans:del:(\d+)$/', $data, $m)) {
        return ['type' => 'del_ask', 'id' => (int) $m[1]];
    }
    if (preg_match('/^bans:del_confirm:(\d+)$/', $data, $m)) {
        return ['type' => 'del_confirm', 'id' => (int) $m[1]];
    }
    if (preg_match('/^bans:del_cancel:(\d+)$/', $data, $m)) {
        return ['type' => 'del_cancel', 'id' => (int) $m[1]];
    }

    return null;
}
