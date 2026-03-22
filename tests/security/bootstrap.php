<?php

declare(strict_types=1);

// Bootstrap for Security tests
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/HttpRuntimeAwareTrait.php';

// Set test environment
putenv('APP_ENV=test');
if (getenv('RUN_SECURITY_TESTS') === false) {
    putenv('RUN_SECURITY_TESTS=0');
}
