<?php

declare(strict_types=1);

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../security/SecurityHeaders.php';
require_once __DIR__ . '/../security/RequestValidator.php';

use Proxbet\Security\RequestValidator;
use Proxbet\Security\SecurityHeaders;

/**
 * @param array<int,string> $methods
 * @param array<int,string> $headers
 */
function proxbet_bootstrap_http_endpoint(
    array $methods,
    array $headers,
    string $contentType = 'application/json; charset=utf-8',
    bool $isApi = true
): void {
    proxbet_bootstrap_env();

    SecurityHeaders::apply(isApi: $isApi);
    RequestValidator::validateRequestSize();

    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    proxbetHandleCors($methods, $headers);
}

function proxbet_get_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function proxbet_get_authorization_header(): string
{
    $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    if ($authHeader !== '') {
        return $authHeader;
    }

    if (!function_exists('apache_request_headers')) {
        return '';
    }

    $headers = apache_request_headers();

    return (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
}

function proxbet_extract_bearer_token(): string
{
    $authHeader = proxbet_get_authorization_header();

    if (!str_starts_with($authHeader, 'Bearer ')) {
        return '';
    }

    return substr($authHeader, 7);
}

/**
 * @return array<string,mixed>
 */
function proxbet_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function proxbet_json_ok(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * @param array<string,mixed> $extra
 */
function proxbet_json_error(string $message, int $status = 400, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(
        ['ok' => false, 'error' => $message] + $extra,
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}
