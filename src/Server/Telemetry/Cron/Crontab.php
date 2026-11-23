<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Cron;

/**
 * A five-field crontab, as Sentry Crons takes it: lists, ranges, steps, month and weekday names, `7` for Sunday,
 * and the macros `@yearly`, `@annually`, `@monthly`, `@weekly`, `@daily`, `@midnight` and `@hourly`.
 *
 * Fields match wall time of a time zone. When both the day of the month and the day of the week are restricted
 * (neither starts with `*`), a day matching either of them matches, as in cron.
 *
 * @internal
 */
final class Crontab
{
    private const MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];
    private const DAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /** How far {@see next()} looks: `0 0 29 2 *` may wait 8 years, over a non-leap 2100. */
    private const HORIZON = 9 * 366 * 86400;

    /**
     * @param array<int, true> $minutes
     * @param array<int, true> $hours
     * @param array<int, true> $days
     * @param array<int, true> $months
     * @param array<int, true> $weekdays 0 is Sunday.
     */
    private function __construct(
        private readonly array $minutes,
        private readonly array $hours,
        private readonly array $days,
        private readonly array $months,
        private readonly array $weekdays,
        private readonly bool $anyDay,
        private readonly bool $anyWeekday,
        private readonly bool $anyHour,
    ) {}

    /**
     * @throws \InvalidArgumentException
     */
    public static function parse(string $expression): self
    {
        $expression = \trim($expression);
        $expression = self::MACROS[\strtolower($expression)] ?? $expression;
        $fields = \preg_split('/\s+/', $expression);
        \is_array($fields) && \count($fields) === 5
            or throw new \InvalidArgumentException('it needs 5 fields');

        [$minute, $hour, $day, $month, $weekday] = $fields;
        // `?`, "no specific value", is a whole day field
        $day === '?' and $day = '*';
        $weekday === '?' and $weekday = '*';
        $weekdays = self::field($weekday, 0, 7, self::DAYS);
        isset($weekdays[7]) and $weekdays[0] = true;
        unset($weekdays[7]);

        return new self(
            self::field($minute, 0, 59),
            self::field($hour, 0, 23),
            self::field($day, 1, 31),
            self::field($month, 1, 12, self::MONTHS),
            $weekdays,
            \str_starts_with($day, '*'),
            \str_starts_with($weekday, '*'),
            \str_starts_with($hour, '*'),
        );
    }

    /**
     * The first minute after `$after` that matches, in Unix seconds; null when none comes within 9 years.
     *
     * Time goes forward in real seconds, so a wall time skipped when clocks go forward is no slot, and one
     * repeated when they go back is a slot once, as cron runs a job of a fixed hour; a job whose hour field
     * starts with `*` keeps its slots in both repeated hours.
     */
    public function next(int $after, \DateTimeZone $zone): ?int
    {
        $time = \intdiv(\max(0, $after), 60) * 60 + 60;
        $limit = $after + self::HORIZON;
        while ($time <= $limit) {
            $local = (new \DateTimeImmutable("@$time"))->setTimezone($zone);
            [$month, $day, $hour, $minute, $weekday] = \array_map(\intval(...), \explode(' ', $local->format('n j G i w')));

            if (!isset($this->months[$month])) {
                $time = self::forward($time, $local->modify('first day of next month')->setTime(0, 0));
            } elseif (!$this->dayMatches($day, $weekday)) {
                $time = self::forward($time, $local->setTime(0, 0)->modify('+1 day'));
            } elseif (!isset($this->hours[$hour])) {
                $time = self::step($time, $time + (60 - $minute) * 60, $zone);
            } elseif (!isset($this->minutes[$minute])) {
                $time = self::step($time, $time + ($this->nextMinute($minute) - $minute) * 60, $zone);
            } elseif (!$this->anyHour && self::repeated($time, $local, $zone)) {
                $time += 60;
            } else {
                return $time;
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $names
     * @return array<int, true>
     * @throws \InvalidArgumentException
     */
    private static function field(string $field, int $min, int $max, array $names = []): array
    {
        $values = [];
        foreach (\explode(',', \strtolower($field)) as $item) {
            \preg_match('/^(\*|[a-z0-9]+)(?:-([a-z0-9]+))?(?:\/(\d{1,3}))?$/D', $item, $match) === 1
                or throw new \InvalidArgumentException("invalid field `$field`");

            $step = isset($match[3]) ? (int) $match[3] : 1;
            $step >= 1 or throw new \InvalidArgumentException("invalid step in `$field`");
            if ($match[1] === '*') {
                ($match[2] ?? '') === '' or throw new \InvalidArgumentException("invalid field `$field`");
                [$from, $to] = [$min, $max];
            } else {
                $from = self::value($match[1], $min, $max, $names, $field);
                $to = ($match[2] ?? '') === ''
                    ? (isset($match[3]) ? $max : $from)
                    : self::value($match[2], $min, $max, $names, $field);
                $from <= $to or throw new \InvalidArgumentException("invalid range in `$field`");
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = true;
            }
        }

        return $values;
    }

    /**
     * @param array<string, int> $names
     * @throws \InvalidArgumentException
     */
    private static function value(string $value, int $min, int $max, array $names, string $field): int
    {
        $number = \preg_match('/^\d+$/D', $value) === 1 ? (int) $value : ($names[$value] ?? null);
        $number !== null && $number >= $min && $number <= $max
            or throw new \InvalidArgumentException("`$value` is out of range in `$field`");

        return $number;
    }

    /**
     * The next local midnight or first of a month; a minute later when the zone puts it at or before `$time`.
     */
    private static function forward(int $time, \DateTimeImmutable $local): int
    {
        return \max($time + 60, $local->getTimestamp());
    }

    /**
     * A step forward in real seconds that stops at a change of clocks in between: Pacific/Chatham jumps from
     * 02:45 to 03:45, so an hour later than 02:00 is 04:00 and would skip the wall times 03:45 to 03:59.
     */
    private static function step(int $time, int $target, \DateTimeZone $zone): int
    {
        foreach ($zone->getTransitions($time + 1, $target - 1) ?: [] as $transition) {
            $at = (int) $transition['ts'];
            if ($at > $time && $at < $target) {
                return \max($time + 60, \intdiv($at + 59, 60) * 60);
            }
        }

        return $target;
    }

    /**
     * Whether the wall time of `$time` was already shown, by clocks that went back.
     */
    private static function repeated(int $time, \DateTimeImmutable $local, \DateTimeZone $zone): bool
    {
        $wall = $local->format('Y-m-d H:i');
        foreach ([3600, 1800] as $shift) {
            $earlier = (new \DateTimeImmutable('@' . ($time - $shift)))->setTimezone($zone);
            if ($earlier->format('Y-m-d H:i') === $wall) {
                return true;
            }
        }

        return false;
    }

    private function dayMatches(int $day, int $weekday): bool
    {
        $byDay = isset($this->days[$day]);
        $byWeekday = isset($this->weekdays[$weekday]);

        return $this->anyDay || $this->anyWeekday ? $byDay && $byWeekday : $byDay || $byWeekday;
    }

    /**
     * The next listed minute after `$minute`, or 60: the next hour.
     */
    private function nextMinute(int $minute): int
    {
        for ($next = $minute + 1; $next < 60; ++$next) {
            if (isset($this->minutes[$next])) {
                return $next;
            }
        }

        return 60;
    }
}
