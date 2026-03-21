<?php

declare(strict_types=1);

// Bootstrap for Security tests
require_once __DIR__ . '/../../vendor/autoload.php';

// Set test environment
putenv('APP_ENV=test');
putenv('RUN_SECURITY_TESTS=1');
