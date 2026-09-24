<?php

declare(strict_types=1);

namespace App;

final class Config
{
    /** @return array<string, string> */
    public static function load(string $root): array
    {
        $values = [];
        $path = $root . '/.env';

        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = trim($value, " \t\n\r\0\x0B\"");
            }
        }

        foreach (array_keys($values) as $key) {
            $env = getenv($key);

            if ($env !== false) {
                $values[$key] = $env;
            }
        }

        foreach (['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'MEMCACHED_HOST', 'MEMCACHED_PORT', 'RSS_URL', 'RSS_SOURCE_NAME', 'CACHE_TTL', 'APP_TIMEZONE'] as $key) {
            if (!isset($values[$key]) && ($env = getenv($key)) !== false) {
                $values[$key] = $env;
            }
        }

        return $values + [
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_NAME' => 'news_aggregator',
            'DB_USER' => 'news', 'DB_PASSWORD' => 'news', 'MEMCACHED_HOST' => '127.0.0.1',
            'MEMCACHED_PORT' => '11211', 'RSS_URL' => 'https://ria.ru/export/rss2/archive/index.xml',
            'RSS_SOURCE_NAME' => 'РИА Новости', 'CACHE_TTL' => '120', 'APP_TIMEZONE' => 'UTC', 'APP_ENV' => 'production',
        ];
    }
}
