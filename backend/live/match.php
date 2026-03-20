<?php

declare(strict_types=1);

namespace Proxbet\Live;

final class MatchUtil
{
    public static function isSameTeams(?string $dbHome, ?string $dbAway, ?string $o1, ?string $o2): bool
    {
        if ($dbHome === null || $dbAway === null || $o1 === null || $o2 === null) {
            return false;
        }

        return trim($dbHome) === trim($o1) && trim($dbAway) === trim($o2);
    }
}
