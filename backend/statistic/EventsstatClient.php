<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

use Proxbet\Core\HttpClient;
use Proxbet\Statistic\Interfaces\EventsstatClientInterface;

final class EventsstatClient implements EventsstatClientInterface
{
    private const USER_AGENT = 'proxbets-stat/1.0';

    public function __construct(private Config $config)
    {
    }

    /**
     * @return array{ok:bool, status:int, rawJson:string, error:?string, attempts:int}
     */
    public function fetchGameRawJson(string $sgi): array
    {
        $sgi = trim($sgi);
        if ($sgi === '') {
            return ['ok' => false, 'status' => 0, 'rawJson' => '', 'error' => 'Empty SGI', 'attempts' => 0];
        }

        $url = $this->config->apiBaseUrl . '/ru/services-api/SiteService/Game?gameId=' . rawurlencode($sgi);
        $res = HttpClient::getWithRetry($url, $this->config->timeoutMs, $this->config->retryCount, self::USER_AGENT);

        return [
            'ok' => $res['ok'],
            'status' => $res['status'],
            'rawJson' => $res['body'],
            'error' => $res['error'],
            'attempts' => $res['attempts'],
        ];
    }
}
