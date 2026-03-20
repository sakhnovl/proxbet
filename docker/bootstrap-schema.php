<?php

declare(strict_types=1);

require_once '/var/www/html/backend/line/env.php';
require_once '/var/www/html/backend/line/db.php';

use Proxbet\Line\Db;
use Proxbet\Line\Env;

Env::load('/var/www/html/.env');
Db::connectFromEnv();

fwrite(STDOUT, "[INFO] Database schema is ready" . PHP_EOL);
