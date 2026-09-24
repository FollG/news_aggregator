<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    /** @param array<string, string> $config */
    public static function connect(array $config): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT'], $config['DB_NAME']);

        return new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
