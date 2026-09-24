#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Database;
use App\MigrationRunner;

$config = require dirname(__DIR__) . '/bootstrap.php';
try {
    (new MigrationRunner(Database::connect($config), dirname(__DIR__) . '/database/migrations'))->run();
    fwrite(STDOUT, "Database migrations are up to date.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
