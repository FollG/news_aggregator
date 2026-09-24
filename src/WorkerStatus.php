<?php

declare(strict_types=1);

namespace App;

use PDO;

final readonly class WorkerStatus
{
    public function __construct(private PDO $db, private string $name) {}

    public function started(): void
    {
        $this->db->prepare('INSERT INTO worker_status (worker_name, last_started_at) VALUES (:name, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_started_at = UTC_TIMESTAMP()')->execute(['name' => $this->name]);
    }

    public function succeeded(): void
    {
        $this->db->prepare('INSERT INTO worker_status (worker_name, last_success_at, last_error_message) VALUES (:name, UTC_TIMESTAMP(), NULL) ON DUPLICATE KEY UPDATE last_success_at = UTC_TIMESTAMP(), last_error_message = NULL')->execute(['name' => $this->name]);
    }

    public function failed(string $message): void
    {
        $this->db->prepare('INSERT INTO worker_status (worker_name, last_error_at, last_error_message) VALUES (:name, UTC_TIMESTAMP(), :message) ON DUPLICATE KEY UPDATE last_error_at = UTC_TIMESTAMP(), last_error_message = VALUES(last_error_message)')->execute(['name' => $this->name, 'message' => mb_substr($message, 0, 1000)]);
    }
}
