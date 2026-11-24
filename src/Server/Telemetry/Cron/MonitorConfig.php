<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Cron;

/**
 * The monitor part of a check-in: the `monitor_config` of a Sentry check-in, or the query of a ping URL.
 *
 * As Sentry upserts a monitor, a field the config leaves out keeps the value the monitor has, and a field sent
 * as `null` gets its default.
 *
 * @internal
 */
final class MonitorConfig
{
    /** Minutes, the defaults of Sentry */
    public const DEFAULT_MARGIN = 1;

    public const DEFAULT_MAX_RUNTIME = 30;

    /** The longest margin and max runtime Sentry takes: 28 days in minutes */
    public const MAX_MINUTES = 40320;

    /** @var array<string, true>|null */
    private static ?array $zones = null;

    /**
     * @param Schedule|null $schedule Null keeps the schedule of the monitor.
     * @param non-empty-string|null $timezone An IANA time zone; null keeps the one of the monitor.
     * @param int<1, max>|null $margin Minutes; null keeps the one of the monitor.
     * @param int<1, max>|null $maxRuntime Minutes; null keeps the one of the monitor.
     */
    public function __construct(
        public readonly ?Schedule $schedule = null,
        public readonly ?string $timezone = null,
        public readonly ?int $margin = null,
        public readonly ?int $maxRuntime = null,
    ) {}

    /**
     * Reads a Sentry `monitor_config`. Sentry drops a config with any invalid field, and so does Trap.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $config): self
    {
        return new self(
            schedule: ($config['schedule'] ?? null) === null ? null : Schedule::fromArray($config['schedule']),
            timezone: \array_key_exists('timezone', $config) ? self::timezone($config['timezone'] ?? 'UTC') : null,
            margin: \array_key_exists('checkin_margin', $config)
                ? self::minutes($config['checkin_margin'] ?? self::DEFAULT_MARGIN, 'checkin_margin')
                : null,
            maxRuntime: \array_key_exists('max_runtime', $config)
                ? self::minutes($config['max_runtime'] ?? self::DEFAULT_MAX_RUNTIME, 'max_runtime')
                : null,
        );
    }

    /**
     * @return non-empty-string
     * @throws \InvalidArgumentException For anything but an IANA time zone name.
     */
    public static function timezone(mixed $name): string
    {
        self::$zones ??= \array_fill_keys(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
        \is_string($name) && $name !== '' && isset(self::$zones[$name])
            or throw new \InvalidArgumentException(\sprintf(
                'unknown time zone `%s`',
                \is_scalar($name) ? (string) $name : \get_debug_type($name),
            ));

        return $name;
    }

    /**
     * @return int<1, max>
     * @throws \InvalidArgumentException
     */
    public static function minutes(mixed $value, string $name): int
    {
        $minutes = match (true) {
            \is_int($value) => $value,
            \is_float($value) && \is_finite($value) => (int) \round($value),
            \is_string($value) && \preg_match('/^\s*\d+\s*$/D', $value) === 1 => (int) $value,
            default => null,
        };
        $minutes !== null && $minutes >= 1 && $minutes <= self::MAX_MINUTES
            or throw new \InvalidArgumentException(\sprintf('%s must be 1 to %d minutes', $name, self::MAX_MINUTES));

        return $minutes;
    }
}
