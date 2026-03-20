<?php

declare(strict_types=1);

/**
 * @return array{apiBase:string, adminIds:int[], statePath:string, db:PDO, state:array}
 */
function makeBotContext(string $apiBase, array $adminIds, string $statePath, PDO $db, array &$state): array
{
    return [
        'apiBase' => $apiBase,
        'adminIds' => $adminIds,
        'statePath' => $statePath,
        'db' => $db,
        'state' => &$state,
    ];
}
