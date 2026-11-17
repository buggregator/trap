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

    /**
     * ISO 8601 in UTC with milliseconds.
     *
     * @param float $timestamp Unix time in seconds.
     */
    public static function format(float $timestamp): string
    {
        $time = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $timestamp));

        return $time === false ? '' : $time->format('Y-m-d\TH:i:s.vP');
    }

    /**
     * @return array<non-empty-string, mixed> The property of the input schema.
     */
    public static function schema(string $description): array
    {
        return [
            'type' => 'string',
            'description' => $description . ' ISO 8601 such as `2026-11-16T10:00:00Z` (UTC without an offset), '
                . 'or a duration back from now: `30s`, `5m`, `2h`, `1d`, `1w`.',
        ];
    }

    /**
     * Reads a time argument: ISO 8601, or a duration back from now as `30s`, `5m`, `2h`, `1d`, `1w`.
     *
     * @param array<array-key, mixed> $arguments
     * @param float $now Unix time in seconds the durations count back from.
     *
     * @return float|null Unix time in seconds; null when the argument is missing.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArguments(array $arguments, string $name, float $now): ?float
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return null;
        }

        \is_string($value) or throw new \InvalidArgumentException("`$name` must be a string.");
        $value = \trim($value);

        if (\preg_match('/^(\d+(?:\.\d+)?)\s*([smhdw])$/i', $value, $match) === 1) {
            return $now - (float) $match[1] * self::UNITS[\strtolower($match[2])];
        }

        // The pattern keeps out the words the date parser takes, such as `tomorrow`
        $iso = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:[.,]\d+)?)?)?(?:Z|[+-]\d{2}(?::?\d{2})?)?$/i';
        if (\preg_match($iso, $value) === 1) {
            try {
                $time = new \DateTimeImmutable(\str_replace(',', '.', $value), new \DateTimeZone('UTC'));
                // A date such as February 30 is parsed with a warning, and rolled over
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors === false || $errors['warning_count'] === 0) {
                    return (float) $time->format('U.u');
                }
            } catch (\Exception) {
                // Reported below, as any other malformed value
            }
        }

        throw new \InvalidArgumentException(\sprintf(
            '`%s` must be ISO 8601, such as `2026-11-16T10:00:00Z`, or a duration back from now, such as '
            . '`30s`, `5m`, `2h`, `1d`; got `%s`.',
            $name,
            $value,
        ));
    }

    /**
     * Reads `from` and `to`.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @return array{float|null, float|null}
     *
     * @throws \InvalidArgumentException
     */
    public static function range(array $arguments, float $now): array
    {
        $from = self::fromArguments($arguments, 'from', $now);
        $to = self::fromArguments($arguments, 'to', $now);
        $from === null || $to === null || $from <= $to or throw new \InvalidArgumentException(
            '`from` is later than `to`.',
        );

        return [$from, $to];
    }
}
