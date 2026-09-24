<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final readonly class MigrationRunner
{
    public function __construct(private PDO $db, private string $directory) {}

    public function run(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(255) PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $path) {
            $version = basename($path);
            $sql = file_get_contents($path);

            if ($sql === false) {
                throw new RuntimeException("Migration {$version} cannot be read.");
            }

            $checksum = hash('sha256', $sql);
            $query = $this->db->prepare('SELECT checksum FROM schema_migrations WHERE version = :version');
            $query->execute(['version' => $version]);
            $applied = $query->fetchColumn();

            if ($applied !== false) {
                if (!hash_equals((string) $applied, $checksum)) {
                    throw new RuntimeException("Applied migration {$version} was changed.");
                }

                continue;
            }

            $this->executeStatements($sql, $version);
            $insert = $this->db->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (:version, :checksum)');
            $insert->execute(['version' => $version, 'checksum' => $checksum]);
        }
    }

    private function executeStatements(string $sql, string $version): void
    {
        foreach (preg_split('/;\s*(?:\R|$)/', $sql) ?: [] as $statement) {
            if (trim($statement) === '') continue;

            try {
                $this->db->exec($statement);
            } catch (\Throwable $exception) {
                throw new RuntimeException("Migration {$version} failed: " . $exception->getMessage(), previous: $exception);
            }
        }
    }
}
