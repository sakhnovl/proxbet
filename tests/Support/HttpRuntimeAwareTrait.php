<?php

declare(strict_types=1);

namespace Proxbet\Tests\Support;

trait HttpRuntimeAwareTrait
{
    protected function isRuntimeAvailable(string $apiBaseUrl): bool
    {
        $url = rtrim($apiBaseUrl, '/') . '/backend/healthz.php';
        $ch = curl_init($url);

        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_NOBODY => true,
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status > 0;
    }
}
