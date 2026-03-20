<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

final class Config
{
    public string $apiBaseUrl;
    public int $timeoutMs;
    public int $retryCount;
    public int $sleepMs;
    public int $staleAfterSeconds;
    public string $statsVersion;

    public function __construct()
    {
        $this->apiBaseUrl = rtrim(getenv('STAT_API_BASE_URL') ?: 'https://eventsstat.com', '/');
        $this->timeoutMs = self::intEnv('STAT_REQUEST_TIMEOUT_MS', 10000, 1000, 60000);
        $this->retryCount = self::intEnv('STAT_RETRY_COUNT', 3, 0, 10);
        $this->sleepMs = self::intEnv('STAT_SLEEP_MS', 250, 0, 10000);
        $this->staleAfterSeconds = self::intEnv('STAT_STALE_AFTER_SECONDS', 21600, 300, 604800);
        $this->statsVersion = trim((string) (getenv('STAT_VERSION') ?: 'ht-v2'));
        if ($this->statsVersion === '') {
            $this->statsVersion = 'ht-v2';
        }
    }

    private static function intEnv(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return $default;
        }

        $v = (int) $raw;
        if ($v < $min) {
            return $min;
        }
        if ($v > $max) {
            return $max;
        }

        return $v;
    }
}
