#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Cache;
use App\Database;
use App\RssImporter;

$config = require dirname(__DIR__) . '/bootstrap.php';

try {
    $result = (new RssImporter(Database::connect($config), Cache::connect($config)))->import($config);
    fwrite(STDOUT, sprintf("RSS import: %s, items: %d\n", $result['status'], $result['items']));
} catch (Throwable $exception) {
    fwrite(STDERR, 'RSS import failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
