<?php

declare(strict_types=1);

namespace Proxbet\Live;

require_once __DIR__ . '/../core/HttpClient.php';

use Proxbet\Core\HttpClient;

final class Client
{
    /**
     * @return array<string,mixed>
     */
    public static function fetchLiveJson(): array
    {
        $url = getenv('API_URL_LIVE') ?: '';
        if ($url === '') {
            throw new \RuntimeException('API_URL_LIVE is not set in .env');
        }

        $timeoutMs = (int) (getenv('HTTP_TIMEOUT') ?: 20) * 1000;
        $maxRetries = (int) (getenv('HTTP_RETRIES') ?: 3);

        return HttpClient::getJson($url, $timeoutMs, $maxRetries, 'proxbets-live/1.0');
    }
}
