<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use JsonException;

final class NewsRequest
{
    /**
     * @throws Exception
     */
    public static function parse(array $query, DateTimeZone $displayTimezone): array
    {
        $category = self::string($query['category'] ?? null);

        if ($category !== null && preg_match('/^[\pL\pN-]{1,255}$/u', $category) !== 1) {
            $category = null;
        }

        $date = self::validDate(self::string($query['date'] ?? null));
        $from = $until = null;

        if ($date !== null) {
            $local = new DateTimeImmutable($date . ' 00:00:00', $displayTimezone);
            $from = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $until = $local->modify('+1 day')
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        return [
            'category' => $category,
            'date' => $date,
            'from' => $from,
            'until' => $until,
            'before' => self::cursor(self::string($query['before'] ?? null))
        ];
    }

    /** @param array{publishedAt:string,id:int} $cursor
     * @throws JsonException
     */
    public static function encodeCursor(array $cursor): string
    {
        return rtrim(strtr(base64_encode(json_encode($cursor, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private static function string(mixed $value): ?string { return is_string($value) ? $value : null; }

    private static function validDate(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @return array{publishedAt:string,id:int}|null */
    private static function cursor(?string $value): ?array
    {
        if ($value === null || strlen($value) > 128) return null;

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) return null;

        try {
            $cursor = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($cursor)
            || !is_string($cursor['publishedAt'] ?? null)
            || !is_int($cursor['id'] ?? null)
            || $cursor['id'] < 1)
        {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $cursor['publishedAt'],
            new DateTimeZone('UTC')
        );

        return $date !== false && $date->format('Y-m-d H:i:s') === $cursor['publishedAt']
            ? ['publishedAt' => $cursor['publishedAt'], 'id' => $cursor['id']] : null;
    }
}
