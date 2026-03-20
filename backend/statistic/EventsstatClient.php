<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

final class EventsstatClient
{
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
        $res = Http::getWithRetry($url, $this->config->timeoutMs, $this->config->retryCount);

        return [
            'ok' => $res['ok'],
            'status' => $res['status'],
            'rawJson' => $res['body'],
            'error' => $res['error'],
            'attempts' => $res['attempts'],
        ];
    }
}
