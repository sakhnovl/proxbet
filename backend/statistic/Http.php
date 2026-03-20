<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

require_once __DIR__ . '/../core/HttpClient.php';

use Proxbet\Core\HttpClient;

/**
 * @deprecated since version ht-v2, use Proxbet\Core\HttpClient instead
 * This class will be removed in the next major version.
 * Kept for backward compatibility only.
 */
final class Http
{
    /**
     * @return array{ok:bool, status:int, body:string, error:?string, attempts:int}
     */
    public static function getWithRetry(string $url, int $timeoutMs, int $maxRetries): array
    {
        return HttpClient::getWithRetry($url, $timeoutMs, $maxRetries, 'proxbets-stat/1.0');
    }
}
