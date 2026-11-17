<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

/**
 * Times in the arguments and the results of the tools.
 *
 * @internal
 */
final class Time
{
    /** Seconds of a duration unit. */
    private const UNITS = ['s' => 1.0, 'm' => 60.0, 'h' => 3600.0, 'd' => 86400.0, 'w' => 604800.0];

    /** Seconds of the longest duration: 100 years of 365.25 days. */
    private const MAX_DURATION = 3_155_760_000.0;

    /**
     * The pattern keeps out the words the date parser takes, such as `tomorrow`; its four-digit year keeps the
     * microseconds of any time it matches within an integer.
     */
    private const ISO = '/^\d{4}-\d{2}-\d{2}(?<time>[T ]\d{2}:\d{2}(?<seconds>:\d{2}(?:[.,](?<fraction>\d+))?)?)?'
        . '(?:Z|[+-]\d{2}(?::?\d{2})?)?$/i';

    /**
     * ISO 8601 in UTC with milliseconds, cut rather than rounded: the listed time is not later than the event.
     *
     * @param float $timestamp Unix time in seconds.
     */
    public static function format(float $timestamp): string
    {
        $micros = self::micros($timestamp);
        $time = \DateTimeImmutable::createFromFormat(
            'U.u',
            \sprintf('%d.%06d', \intdiv($micros, 1_000_000), $micros % 1_000_000),
        );

        return $time === false ? '' : $time->format('Y-m-d\TH:i:s.vP');
    }

    /**
     * @param float $timestamp Unix time in seconds.
     *
     * @return int Unix time in whole microseconds, as {@see format()} and {@see TimeRange} take it.
     */
    public static function micros(float $timestamp): int
    {
        return (int) \round($timestamp * 1e6);
    }

    /**
     * @return array<non-empty-string, mixed> The property of the input schema.
     */
    public static function schema(string $description): array
    {
        return [
            'type' => 'string',
            'description' => $description . ' ISO 8601 such as `2026-11-16T10:00:00Z` (UTC without an offset), '
                . 'which spans its last written unit, as a date the whole day; or a duration back from now: '
                . '`30s`, `5m`, `2h`, `1d`, `1w`, the unit in lower case.',
        ];
    }

    /**
     * Reads a time argument: ISO 8601, or a duration back from now as `30s`, `5m`, `2h`, `1d`, `1w`.
     *
     * An ISO 8601 time stands for the whole span of its last written unit: `2026-11-16` for the day,
     * `10:00` for the minute, `10:00:00.737` for the millisecond.
     *
     * @param array<array-key, mixed> $arguments
     * @param float $now Unix time in seconds the durations count back from.
     * @param bool $end Whether to take the last microsecond of the span rather than the first one.
     *
     * @return int|null Unix time in microseconds; null when the argument is missing.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArguments(array $arguments, string $name, float $now, bool $end = false): ?int
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return null;
        }

        \is_string($value) or throw new \InvalidArgumentException("`$name` must be a string.");
        $value = \trim($value);

        // Lower case only: `M` reads as months as often as minutes
        if (\preg_match('/^(\d+(?:\.\d+)?)\s*([smhdw])$/', $value, $match) === 1) {
            $seconds = (float) $match[1] * self::UNITS[$match[2]];
            // Microseconds of a longer one may not fit an integer
            $seconds <= self::MAX_DURATION or throw new \InvalidArgumentException(\sprintf(
                '`%s` must be a duration of at most 100 years, such as `5200w`; got `%s`.',
                $name,
                $value,
            ));

            return self::micros($now - $seconds);
        }

        if (\preg_match(self::ISO, $value, $match, \PREG_UNMATCHED_AS_NULL) === 1) {
            try {
                $time = new \DateTimeImmutable(\str_replace(',', '.', $value), new \DateTimeZone('UTC'));
                // A date such as February 30 is parsed with a warning, and rolled over
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors === false || $errors['warning_count'] === 0) {
                    $start = (int) $time->format('U') * 1_000_000 + (int) $time->format('u');
                    $span = match (true) {
                        $match['time'] === null => 86_400_000_000,
                        $match['seconds'] === null => 60_000_000,
                        default => (int) (10 ** \max(0, 6 - \strlen($match['fraction'] ?? ''))),
                    };

                    return $end ? $start + $span - 1 : $start;
                }
            } catch (\Exception) {
                // Reported below, as any other malformed value
            }
        }

        throw new \InvalidArgumentException(\sprintf(
            '`%s` must be ISO 8601, such as `2026-11-16T10:00:00Z`, or a duration back from now, such as '
            . '`30s`, `5m`, `2h`, `1d` (the unit in lower case); got `%s`.',
            $name,
            $value,
        ));
    }

    /**
     * Reads `from` and `to`.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @throws \InvalidArgumentException
     */
    public static function range(array $arguments, float $now): TimeRange
    {
        $from = self::fromArguments($arguments, 'from', $now);
        $to = self::fromArguments($arguments, 'to', $now, end: true);
        $from === null || $to === null || $from <= $to or throw new \InvalidArgumentException(
            '`from` is later than `to`.',
        );

        return new TimeRange($from, $to);
    }
}
