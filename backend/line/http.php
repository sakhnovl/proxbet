<?php

declare(strict_types=1);

namespace Proxbet\Line;

require_once __DIR__ . '/../core/HttpClient.php';

use Proxbet\Core\HttpClient;

/**
 * @deprecated Use Proxbet\Core\HttpClient instead
 * Kept for backward compatibility
 */
final class Http
{
    /**
     * @return array<string,mixed>
     */
    public static function getJson(string $url): array
    {
        $timeoutMs = (int) (getenv('HTTP_TIMEOUT') ?: 20) * 1000;
        $maxRetries = (int) (getenv('HTTP_RETRIES') ?: 3);

        return HttpClient::getJson($url, $timeoutMs, $maxRetries, 'proxbets-parser/1.0');
    }
}
