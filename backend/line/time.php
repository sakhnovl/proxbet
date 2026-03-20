<?php

declare(strict_types=1);

namespace Proxbet\Line;

final class Time
{
    /**
     * API sends S as unix timestamp in seconds (confirmed by sample 1773478800).
     */
    public static function startTimeMoscow(?int $unixSeconds): ?string
    {
        if ($unixSeconds === null || $unixSeconds <= 0) {
            return null;
        }

        $dt = (new \DateTimeImmutable('@' . $unixSeconds))
            ->setTimezone(new \DateTimeZone('Europe/Moscow'));

        return $dt->format('Y-m-d H:i:s');
    }
}
