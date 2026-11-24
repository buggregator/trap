<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Cron;

/**
 * The schedule of a monitor, as the `schedule` of a Sentry `monitor_config`:
 * `{"type": "crontab", "value": "0 3 * * *"}` or `{"type": "interval", "value": 10, "unit": "minute"}`.
 *
 * @internal
 */
final class Schedule
{
    public const CRONTAB = 'crontab';
    public const INTERVAL = 'interval';
    public const UNITS = ['minute', 'hour', 'day', 'week', 'month', 'year'];

    /** Seconds of an interval unit; a month and a year of the Gregorian calendar on average */
    private const SECONDS = [
        'minute' => 60, 'hour' => 3600, 'day' => 86400, 'week' => 604800, 'month' => 2629746, 'year' => 31556952,
    ];

    /** Seconds of the longest interval: 10 years */
    private const MAX_INTERVAL = 315569520;

    /** Seconds of the longest span of a unit, a day of a clock change included */
    private const LONGEST = [
        'minute' => 60, 'hour' => 3600, 'day' => 90000, 'week' => 608400, 'month' => 2682000, 'year' => 31626000,
    ];

    /**
     * @param self::CRONTAB|self::INTERVAL $type
     * @param non-empty-string $value A crontab, or the count of units as digits.
     * @param ''|value-of<self::UNITS> $unit Empty for a crontab.
     */
    private function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly string $unit,
        private readonly ?Crontab $crontab,
    ) {}

    /**
     * @throws \InvalidArgumentException
     */
    public static function crontab(string $expression): self
    {
        $expression = \trim($expression);
        \str_starts_with(\strtolower($expression), '@every')
            and throw new \InvalidArgumentException('`@every` is no crontab: send an interval schedule');
        try {
            $crontab = Crontab::parse($expression);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException("invalid crontab `$expression`: {$e->getMessage()}", 0, $e);
        }
        /** @var non-empty-string $expression Five fields at least */

        return new self(self::CRONTAB, $expression, '', $crontab);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function interval(int $count, string $unit): self
    {
        $unit = \rtrim(\strtolower(\trim($unit)), 's');
        \in_array($unit, self::UNITS, true)
            or throw new \InvalidArgumentException("unknown interval unit `$unit`");
        $count >= 1 && $count <= \intdiv(self::MAX_INTERVAL, self::SECONDS[$unit])
            or throw new \InvalidArgumentException('an interval is 1 minute to 10 years');

        return new self(self::INTERVAL, (string) $count, $unit, null);
    }

    /**
     * Reads the `schedule` object of a monitor config.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(mixed $schedule): self
    {
        \is_array($schedule) or throw new \InvalidArgumentException('the schedule must be an object');
        /** @var mixed $type */
        $type = $schedule['type'] ?? null;
        /** @var mixed $value */
        $value = $schedule['value'] ?? null;

        if ($type === self::CRONTAB) {
            \is_string($value) or throw new \InvalidArgumentException('a crontab schedule needs a string value');
            return self::crontab($value);
        }

        if ($type === self::INTERVAL) {
            // Bounded before the cast: a bigger number would wrap or saturate
            $count = match (true) {
                \is_int($value) => $value,
                \is_string($value) && \preg_match('/^\s*\d{1,12}\s*$/D', $value) === 1 => (int) $value,
                \is_float($value) && \floor($value) === $value && \abs($value) < 1e12 => (int) $value,
                \is_string($value), \is_float($value) => 0,
                default => throw new \InvalidArgumentException('an interval schedule needs a whole number value'),
            };
            /** @var mixed $unit */
            $unit = $schedule['unit'] ?? null;
            \is_string($unit) or throw new \InvalidArgumentException('an interval schedule needs a unit');

            return self::interval($count, $unit);
        }

        throw new \InvalidArgumentException('the schedule type must be crontab or interval');
    }

    /**
     * The first slot after `$after`, in Unix seconds, counted from the minute of `$after` as Sentry counts it;
     * null when none comes.
     *
     * Intervals of days and longer keep the wall time of the zone: a daily run stays at 03:00 across a change
     * of clocks; a month from January 31 is the last day of February.
     */
    public function next(float $after, \DateTimeZone $zone): ?int
    {
        return $this->crontab === null
            ? $this->nth($after, 1, $zone)
            : $this->crontab->next(self::minute($after), $zone);
    }

    /**
     * The first slot after `$time` of the slots that follow `$anchor`, the start of a run: an interval counts from
     * the anchor, so a slot moved by a change of clocks does not move the ones after it. A crontab has no anchor.
     *
     * @return int|null Unix seconds; null when none comes.
     */
    public function after(float $anchor, float $time, \DateTimeZone $zone): ?int
    {
        if ($this->crontab !== null) {
            return $this->crontab->next(self::minute($time), $zone);
        }

        // Below the slot sought, as no slot of the unit is longer; then a few steps up
        $longest = self::LONGEST[$this->unit] ?? 60;
        $k = \max(1, \intdiv(self::minute($time) - self::minute($anchor), (int) $this->value * $longest));
        for ($steps = 0; $steps < 10000; ++$steps, ++$k) {
            $slot = $this->nth($anchor, $k, $zone);
            if ($slot > $time) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, value: string, unit?: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value] + ($this->unit === '' ? [] : ['unit' => $this->unit]);
    }

    /**
     * `crontab 0 3 * * *` or `every 10 minutes`.
     */
    public function describe(): string
    {
        return $this->type === self::CRONTAB
            ? "crontab $this->value"
            : "every $this->value $this->unit" . ($this->value === '1' ? '' : 's');
    }

    private static function minute(float $time): int
    {
        return \intdiv((int) \floor($time), 60) * 60;
    }

    private static function calendar(int $time, int $count, string $unit, \DateTimeZone $zone): int
    {
        $local = (new \DateTimeImmutable("@$time"))->setTimezone($zone);
        if ($unit === 'day' || $unit === 'week') {
            return $local->modify(\sprintf('+%d day', $unit === 'day' ? $count : 7 * $count))->getTimestamp();
        }

        [$year, $month, $day] = \array_map(\intval(...), \explode(' ', $local->format('Y n j')));
        $months = $year * 12 + $month - 1 + ($unit === 'year' ? 12 * $count : $count);
        $year = \intdiv($months, 12);
        $month = $months % 12 + 1;
        $last = (int) $local->setDate($year, $month, 1)->format('t');

        return $local->setDate($year, $month, \min($day, $last))->getTimestamp();
    }

    /**
     * The `$k`-th slot of an interval after the minute of `$anchor`.
     */
    private function nth(float $anchor, int $k, \DateTimeZone $zone): int
    {
        $minute = self::minute($anchor);
        $count = (int) $this->value * $k;

        return match ($this->unit) {
            'minute' => $minute + $count * 60,
            'hour' => $minute + $count * 3600,
            default => self::calendar($minute, $count, $this->unit, $zone),
        };
    }
}
