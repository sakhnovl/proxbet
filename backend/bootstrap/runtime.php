<?php

declare(strict_types=1);

use Proxbet\Line\Env;

function proxbet_root_dir(): string
{
    return dirname(__DIR__, 2);
}

function proxbet_env_path(): string
{
    return proxbet_root_dir() . '/.env';
}

function proxbet_bootstrap_env(): void
{
    Env::load(proxbet_env_path());
}

/**
 * @param list<string> $keys
 */
function proxbet_require_env(array $keys): void
{
    $missing = [];

    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value === false || trim((string) $value) === '') {
            $missing[] = $key;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException('Missing required env: ' . implode(', ', $missing));
    }
}
