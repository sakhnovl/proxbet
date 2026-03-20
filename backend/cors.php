<?php

declare(strict_types=1);

/**
 * @return array<int,string>
 */
function proxbetAllowedOrigins(): array
{
    $allowed = [];

    $appUrl = trim((string) (getenv('APP_URL') ?: ''));
    if ($appUrl !== '') {
        $allowed[] = rtrim($appUrl, '/');
    }

    $extraOrigins = trim((string) (getenv('ALLOWED_ORIGINS') ?: ''));
    if ($extraOrigins !== '') {
        foreach (explode(',', $extraOrigins) as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin !== '') {
                $allowed[] = $origin;
            }
        }
    }

    return array_values(array_unique($allowed));
}

/**
 * @param array<int,string> $methods
 * @param array<int,string> $headers
 */
function proxbetHandleCors(array $methods, array $headers): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '' && in_array(rtrim($origin, '/'), proxbetAllowedOrigins(), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
    header('Access-Control-Allow-Headers: ' . implode(', ', $headers));

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
