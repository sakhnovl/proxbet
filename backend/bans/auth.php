<?php

declare(strict_types=1);

function isAdmin(int $userId, array $adminIds): bool
{
    return in_array($userId, $adminIds, true);
}
