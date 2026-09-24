<?php

declare(strict_types=1);

namespace App;

use Memcached;

final readonly class Cache
{
    private function __construct(private Memcached $client) {}

    /** @param array<string, string> $config */
    public static function connect(array $config): ?self
    {
        if (!class_exists(Memcached::class)) {
            return null;
        }

        $client = new Memcached('news_aggregator');
        $client->setOption(Memcached::OPT_CONNECT_TIMEOUT, 100);
        $client->setOption(Memcached::OPT_RECV_TIMEOUT, 200);

        if ($client->getServerList() === []) {
            $client->addServer($config['MEMCACHED_HOST'], (int) $config['MEMCACHED_PORT']);
        }

        return new self($client);
    }

    /** @template T @param callable():T $factory @return T */
    public function remember(string $key, int $ttl, callable $factory): mixed
    {
        $value = $this->client->get($key);

        if ($this->client->getResultCode() === Memcached::RES_SUCCESS) return $value;

        $staleKey = 'stale:' . $key;
        $stale = $this->client->get($staleKey);
        $hasStale = $this->client->getResultCode() === Memcached::RES_SUCCESS;

        // пока один запрос обновляет список, остальные получают ограниченно устаревшие значения
        $lockKey = 'lock:' . $key;
        if (!$this->client->add($lockKey, 1, 10)) {

            if ($hasStale) return $stale;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                usleep(50_000);
                $value = $this->client->get($key);
                if ($this->client->getResultCode() === Memcached::RES_SUCCESS) return $value;
            }

            return $factory();
        }

        try {
            $value = $factory();
            $this->client->set($key, $value, $ttl);
            $this->client->set($staleKey, $value, max($ttl * 5, 60));
            return $value;
        } finally {
            $this->client->delete($lockKey);
        }
    }

    public function version(string $name): int
    {
        $key = 'version:' . $name;
        $value = $this->client->get($key);

        if ($this->client->getResultCode() !== Memcached::RES_SUCCESS) {
            $this->client->add($key, 1);
            return 1;
        }

        return (int) $value;
    }

    public function bumpVersion(string $name): void
    {
        $key = 'version:' . $name;

        if (!$this->client->increment($key)) {
            $this->client->set($key, 2);
        }
    }
}
