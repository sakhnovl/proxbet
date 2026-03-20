<?php

declare(strict_types=1);

/**
 * Persistent state is minimal:
 * - last_update_id
 * - per-user wizard state for /bans_add and /bans_edit
 *
 * @return array<string,mixed>
 */
function loadState(string $path): array
{
    if (!is_file($path)) {
        return ['last_update_id' => 0, 'users' => []];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return ['last_update_id' => 0, 'users' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['last_update_id' => 0, 'users' => []];
    }

    if (!isset($decoded['last_update_id'])) {
        $decoded['last_update_id'] = 0;
    }
    if (!isset($decoded['users']) || !is_array($decoded['users'])) {
        $decoded['users'] = [];
    }

    return $decoded;
}

function saveState(string $path, array $state): void
{
    $tmp = $path . '.tmp';
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }

    // Best-effort atomic write
    @file_put_contents($tmp, $json, LOCK_EX);
    @rename($tmp, $path);
}

/** @return array<string,mixed>|null */
function getUserWizard(array $state, int $userId): ?array
{
    $users = $state['users'] ?? [];
    if (!is_array($users)) {
        return null;
    }

    $key = (string) $userId;
    $w = $users[$key] ?? null;

    return is_array($w) ? $w : null;
}

function setUserWizard(array &$state, int $userId, array $wizard): void
{
    if (!isset($state['users']) || !is_array($state['users'])) {
        $state['users'] = [];
    }

    $state['users'][(string) $userId] = $wizard;
}

function clearUserWizard(array &$state, int $userId): void
{
    if (!isset($state['users']) || !is_array($state['users'])) {
        return;
    }

    unset($state['users'][(string) $userId]);
}
