<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\Profile;

/**
 * Request parts shared by the Trap and the server destinations.
 *
 * @internal
 */
final class Payload
{
    /**
     * Metadata as query parameters, for bodies that can't carry it: `app_name`, `hostname`, `name`,
     * `clock`, `period` (seconds), `sampling_period` (microseconds, of SPX) and `tags[...]`.
     * Null values and empty tags are left out.
     *
     * The period lets the receiver count the samples of a Speedscope document: Excimer records repeated
     * timer events only in the weight, as `events * period`.
     */
    public static function query(Profile $profile): string
    {
        $meta = $profile->metadata();
        $clock = $meta->options['clock'] ?? null;
        $period = $meta->options['period'] ?? null;
        $samplingPeriod = $meta->options['sampling_period'] ?? null;

        return \http_build_query([
            'app_name' => $meta->appName,
            'hostname' => $meta->hostname,
            'name' => $meta->name,
            'clock' => \is_string($clock) ? $clock : null,
            'period' => \is_float($period) || \is_int($period) ? self::decimal((float) $period) : null,
            'sampling_period' => \is_int($samplingPeriod) ? $samplingPeriod : null,
            'tags' => $meta->tags,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * Writes a non-negative number in plain decimal notation with all its significant digits:
     * a float would be written as `1.0E-10`, and a fixed number of decimals rounds a tiny period to `0`.
     */
    public static function decimal(float $value): string
    {
        // The shortest representation that reads back as the same float.
        $repr = \var_export($value, true);
        if (\preg_match('/^(\d+)(?:\.(\d*))?(?:E([+-]?\d+))?$/i', $repr, $match) !== 1) {
            return $repr;
        }

        $integer = $match[1];
        $fraction = \rtrim($match[2] ?? '', '0');
        $digits = \ltrim($integer . $fraction, '0');
        if ($digits === '') {
            return '0';
        }

        // The position of the decimal point within the significant digits.
        $point = \strlen($integer) + (int) ($match[3] ?? 0) - (\strlen($integer . $fraction) - \strlen($digits));

        return match (true) {
            $point <= 0 => '0.' . \str_repeat('0', -$point) . $digits,
            $point >= \strlen($digits) => $digits . \str_repeat('0', $point - \strlen($digits)),
            default => \substr($digits, 0, $point) . '.' . \substr($digits, $point),
        };
    }

    /**
     * The JSON body of the Buggregator `/api/profiler/store` endpoint.
     *
     * @throws \InvalidArgumentException When the profile can't be encoded as XHProf.
     */
    public static function store(Profile $profile): string
    {
        $meta = $profile->metadata();
        // The store endpoint shows tags only: the name travels as the `name` tag, unless the user has one.
        $tags = $meta->name === null ? $meta->tags : $meta->tags + ['name' => $meta->name];
        $fields = \array_filter([
            'tags' => (object) $tags,
            'app_name' => $meta->appName,
            'hostname' => $meta->hostname,
            'date' => $meta->date->getTimestamp(),
        ], static fn(mixed $value): bool => $value !== null);

        // The encoded profile is spliced in as is instead of being decoded and encoded again.
        return '{"profile":' . $profile->encode(Format::XHProf) . ','
            . \substr(\json_encode(
                $fields,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
            ), 1);
    }
}
