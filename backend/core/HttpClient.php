<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Core\Exceptions\ApiException;

/**
 * Unified HTTP client with retry logic and exponential backoff.
 *
 * Replaces:
 * - backend/line/http.php
 * - backend/statistic/Http.php
 */
final class HttpClient
{
    /**
     * Perform HTTP GET request with retry logic.
     *
     * @return array{ok:bool, status:int, body:string, error:?string, attempts:int}
     */
    public static function getWithRetry(
        string $url,
        int $timeoutMs = 10000,
        int $maxRetries = 3,
        string $userAgent = 'proxbets/1.0'
    ): array {
        $attempt = 0;
        $lastError = null;
        $lastStatus = 0;
        $lastBody = '';

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $res = self::get($url, $timeoutMs, $userAgent);
                $lastStatus = $res['status'];
                $lastBody = $res['body'];

                // Success
                if ($lastStatus >= 200 && $lastStatus < 300) {
                    return [
                        'ok' => true,
                        'status' => $lastStatus,
                        'body' => $lastBody,
                        'error' => null,
                        'attempts' => $attempt,
                    ];
                }

                // Retry only for 429 (rate limit) and 5xx (server errors)
                if ($lastStatus !== 429 && $lastStatus < 500) {
                    return [
                        'ok' => false,
                        'status' => $lastStatus,
                        'body' => $lastBody,
                        'error' => 'HTTP status ' . $lastStatus,
                        'attempts' => $attempt,
                    ];
                }

                $lastError = 'HTTP status ' . $lastStatus;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                
                // Optional logging if Logger is available
                if (class_exists('Proxbet\Line\Logger')) {
                    \Proxbet\Line\Logger::error('HTTP request attempt failed', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                        'url' => $url,
                    ]);
                }
            }

            // Don't sleep after last attempt
            if ($attempt > $maxRetries) {
                break;
            }

            // Exponential backoff with jitter
            $backoffMs = min(15000, 250 * (2 ** ($attempt - 1)));
            
            // Special handling for rate limiting
            if ($lastStatus === 429) {
                $backoffMs = max($backoffMs, 2000);
            }

            usleep($backoffMs * 1000);
        }

        return [
            'ok' => false,
            'status' => $lastStatus,
            'body' => $lastBody,
            'error' => $lastError ?? 'Unknown error',
            'attempts' => $attempt,
        ];
    }

    /**
     * Perform HTTP GET request and parse JSON response.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public static function getJson(
        string $url,
        int $timeoutMs = 20000,
        int $maxRetries = 3,
        string $userAgent = 'proxbets/1.0'
    ): array {
        $res = self::getWithRetry($url, $timeoutMs, $maxRetries, $userAgent);

        if (!$res['ok']) {
            throw new ApiException(
                'HTTP request failed: ' . ($res['error'] ?? 'Unknown error') 
                . ' (status: ' . $res['status'] . ', attempts: ' . $res['attempts'] . ')'
            );
        }

        $decoded = json_decode($res['body'], true);
        if (!is_array($decoded)) {
            throw new ApiException('Response is not valid JSON');
        }

        return $decoded;
    }

    /**
     * Perform single HTTP GET request without retry.
     *
     * @return array{status:int, body:string}
     * @throws \RuntimeException
     */
    private static function get(string $url, int $timeoutMs, string $userAgent): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiException('curl_init failed');
        }

        // Adaptive timeout: use provided timeout, but ensure minimum
        $timeoutSeconds = (int) max(1, (int) ceil($timeoutMs / 1000));
        $connectTimeout = (int) max(1, (int) ceil($timeoutSeconds / 2));

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $userAgent,
                'Connection: keep-alive',
            ],
            // Enable HTTP keep-alive for connection reuse
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 120,
            CURLOPT_TCP_KEEPINTVL => 60,
            // Enable HTTP/2 if available
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            // Enable compression
            CURLOPT_ENCODING => '',
        ]);

        $response = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new ApiException('cURL error: ' . $errNo . ' ' . $err);
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
