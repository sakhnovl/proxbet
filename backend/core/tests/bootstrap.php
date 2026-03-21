<?php

declare(strict_types=1);

// Bootstrap for Core tests
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../backend/bootstrap/autoload.php';

// Set test environment
putenv('APP_ENV=test');
