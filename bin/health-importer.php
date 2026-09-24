#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Database;

$config = require dirname(__DIR__) . '/bootstrap.php';
try {
    $statement = Database::connect($config)->prepare("SELECT last_success_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE FROM worker_status WHERE worker_name = 'rss-importer'");
    $statement->execute();
    if ($statement->fetchColumn() !== 1) throw new RuntimeException('RSS importer has not succeeded in the last 15 minutes.');
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
