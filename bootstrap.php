<?php

declare(strict_types=1);

use App\Config;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require __DIR__ . '/src/' . substr($class, 4) . '.php';
    }
});

$config = Config::load(__DIR__);
date_default_timezone_set($config['APP_TIMEZONE']);

return $config;
