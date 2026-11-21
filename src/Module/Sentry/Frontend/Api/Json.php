<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend\Api;

/**
 * Value formats of the Sentry API of the UI.
 *
 * @internal
 */
final class Json
{
    /**
     * Unix seconds as ISO 8601 in UTC with microseconds: `2026-09-04T05:33:12.123456Z`.
     */
    public static function time(?float $time): ?string
    {
        if ($time === null) {
            return null;
        }

        $seconds = (int) \floor($time);
        $micro = (int) \round(($time - (float) $seconds) * 1_000_000.0);
        if ($micro >= 1_000_000) {
            ++$seconds;
            $micro -= 1_000_000;
        }

        return \gmdate('Y-m-d\TH:i:s', $seconds) . \sprintf('.%06dZ', $micro);
    }

    /**
     * Milliseconds from `$base` to `$time`; 0 when either is unknown.
     */
    public static function offsetMs(?float $base, ?float $time): int
    {
        return $base === null || $time === null ? 0 : (int) \round(($time - $base) * 1000.0);
    }

    /**
     * An attribute map, or null when empty: an empty PHP array would be a JSON list.
     */
    public static function map(array $attributes): ?array
    {
        return $attributes === [] ? null : $attributes;
    }

    /**
     * @template T
     * @param list<T> $items
     * @param int<1, max> $page
     * @param int<1, max> $limit
     * @return array{data: list<T>, meta: array{total: int<0, max>, page: int<1, max>, limit: int<1, max>}}
     */
    public static function page(array $items, int $page, int $limit): array
    {
        return [
            'data' => \array_slice($items, ($page - 1) * $limit, $limit),
            'meta' => ['total' => \count($items), 'page' => $page, 'limit' => $limit],
        ];
    }

    /**
     * @return int<1, max>
     */
    public static function pageNumber(int $page): int
    {
        return \max(1, $page);
    }

    /**
     * @param int<1, 500> $default
     * @return int<1, 500> The default for a value out of 1..500.
     */
    public static function limit(int $limit, int $default): int
    {
        return $limit >= 1 && $limit <= 500 ? $limit : $default;
    }
}
