<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Telemetry\Cron;

use Buggregator\Trap\Server\Telemetry\Cron\Crontab;
use Buggregator\Trap\Server\Telemetry\Cron\Schedule;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(Schedule::class)]
#[Covers(Crontab::class)]
#[Test]
final class ScheduleTest
{
    #[DataSet(['5,10-20/5 * * * *', 'UTC', '2026-11-23T12:00:00Z', ['12:05', '12:10', '12:15', '12:20', '13:05']], 'list, range and step')]
    #[DataSet(['*/15 * * * *', 'UTC', '2026-11-23T12:07:30Z', ['12:15', '12:30', '12:45', '13:00']], 'step of any')]
    #[DataSet(['0 */6 * * *', 'UTC', '2026-11-23T05:59:59Z', ['06:00', '12:00', '18:00', '00:00']], 'step of hours')]
    #[DataSet(['@hourly', 'UTC', '2026-11-23T12:00:00Z', ['13:00', '14:00']], 'macro, strictly after a slot')]
    public function testNextSlotsOfOneDay(string $crontab, string $zone, string $after, array $times): void
    {
        $slots = self::slots(Schedule::crontab($crontab), $after, \count($times), $zone);

        Assert::same(\array_map(static fn(int $slot): string => \gmdate('H:i', $slot), $slots), $times);
    }

    #[DataSet(['0 9 * * 1-5', 'America/New_York', '2026-11-20T15:00:00Z', '2026-11-23T14:00:00Z'], 'weekdays in New York')]
    #[DataSet(['0 0 * jan,FEB sun', 'UTC', '2026-11-23T00:00:00Z', '2027-01-03T00:00:00Z'], 'names, day of week and month')]
    #[DataSet(['0 0 * * 7', 'UTC', '2026-11-23T00:00:00Z', '2026-11-29T00:00:00Z'], '7 is Sunday')]
    #[DataSet(['0 0 13 * 5', 'UTC', '2026-11-01T00:00:00Z', '2026-11-06T00:00:00Z'], 'day of month or of week: the Friday first')]
    #[DataSet(['0 0 13 * 5', 'UTC', '2026-11-12T00:00:00Z', '2026-11-13T00:00:00Z'], 'day of month or of week: the 13th')]
    #[DataSet(['0 0 */2 * 5', 'UTC', '2026-11-01T00:00:00Z', '2026-11-13T00:00:00Z'], 'a starred day of month: both match')]
    #[DataSet(['0 0 29 2 *', 'UTC', '2026-03-01T00:00:00Z', '2028-02-29T00:00:00Z'], 'leap day')]
    #[DataSet(['@daily', 'Asia/Kolkata', '2026-11-23T12:00:00Z', '2026-11-23T18:30:00Z'], 'midnight of a half-hour zone')]
    #[DataSet(['@monthly', 'UTC', '2026-11-23T00:00:00Z', '2026-12-01T00:00:00Z'], 'monthly')]
    public function testNextSlot(string $crontab, string $zone, string $after, string $next): void
    {
        Assert::same(self::slots(Schedule::crontab($crontab), $after, 1, $zone), [self::time($next)]);
    }

    public function testWallTimeSkippedByClocksGoingForwardIsNoSlot(): void
    {
        $slots = self::slots(Schedule::crontab('30 2 * * *'), '2026-03-28T12:00:00Z', 1, 'Europe/Berlin');

        Assert::same($slots, [self::time('2026-03-30T00:30:00Z')]);
    }

    public function testWallTimeRepeatedByClocksGoingBackIsOneSlotOfAFixedHour(): void
    {
        $slots = self::slots(Schedule::crontab('30 2 * * *'), '2026-10-24T12:00:00Z', 2, 'Europe/Berlin');

        Assert::same($slots, [self::time('2026-10-25T00:30:00Z'), self::time('2026-10-26T01:30:00Z')]);
    }

    public function testJobOfAnyHourKeepsItsSlotsInRealTimeAcrossChangesOfClocks(): void
    {
        $back = self::slots(Schedule::crontab('*/30 * * * *'), '2026-10-25T00:00:00Z', 3, 'Europe/Berlin');
        $forward = self::slots(Schedule::crontab('*/30 * * * *'), '2026-03-29T00:30:00Z', 1, 'Europe/Berlin');

        Assert::same($back, [self::time('2026-10-25T00:30:00Z'), self::time('2026-10-25T01:00:00Z'), self::time('2026-10-25T01:30:00Z')]);
        Assert::same($forward, [self::time('2026-03-29T01:00:00Z')]);
    }

    #[DataSet(['0 0 31 2 *'], 'February 31')]
    #[DataSet(['0 0 30 2 *'], 'February 30')]
    public function testNoSlotOfADayThatNeverComes(string $crontab): void
    {
        Assert::same(self::slots(Schedule::crontab($crontab), '2026-01-01T00:00:00Z', 1), []);
    }

    #[DataSet(['0 * * * *', 'Pacific/Chatham', '2026-09-26T13:00:00Z', ['2026-09-26T13:15:00Z', '2026-09-26T14:15:00Z']], 'Chatham: 03:00 skipped at 02:45')]
    #[DataSet(['50 3 * * *', 'Pacific/Chatham', '2026-09-26T12:00:00Z', ['2026-09-26T14:05:00Z']], 'Chatham: 03:50 after the jump')]
    #[DataSet(['*/15 * * * *', 'Pacific/Chatham', '2026-09-26T13:40:00Z', ['2026-09-26T13:45:00Z', '2026-09-26T14:00:00Z']], 'Chatham: 03:45 right after the jump')]
    #[DataSet(['15 2 * * *', 'Australia/Lord_Howe', '2026-10-03T00:00:00Z', ['2026-10-04T15:15:00Z']], 'Lord Howe: half an hour forward')]
    #[DataSet(['0 9 * * *', 'Asia/Kathmandu', '2026-11-23T00:00:00Z', ['2026-11-23T03:15:00Z']], 'Kathmandu: +05:45')]
    #[DataSet(['30 0 * * *', 'America/Santiago', '2026-09-04T12:00:00Z', ['2026-09-05T04:30:00Z', '2026-09-07T03:30:00Z']], 'Santiago: midnight skipped')]
    #[DataSet(['0 0 * * *', 'America/Havana', '2026-03-06T12:00:00Z', ['2026-03-07T05:00:00Z', '2026-03-09T04:00:00Z']], 'Havana: midnight skipped')]
    #[DataSet(['0 0 31 * *', 'UTC', '2026-11-23T00:00:00Z', ['2026-12-31T00:00:00Z', '2027-01-31T00:00:00Z']], 'the 31st of the months that have it')]
    public function testZonesOfOddOffsetsAndChanges(string $crontab, string $zone, string $after, array $slots): void
    {
        Assert::same(self::slots(Schedule::crontab($crontab), $after, \count($slots), $zone), \array_map(self::time(...), $slots));
    }

    #[DataSet(['5/15 * * * *', ['12:05', '12:20', '12:35']], 'value and step')]
    #[DataSet(['0 0-23/8 * * *', ['16:00', '00:00']], 'range and step')]
    public function testStepsFromAValue(string $crontab, array $times): void
    {
        $slots = self::slots(Schedule::crontab($crontab), '2026-11-23T12:00:00Z', \count($times));

        Assert::same(\array_map(static fn(int $slot): string => \gmdate('H:i', $slot), $slots), $times);
    }

    public function testWeekdayRangeToSevenAndQuestionMark(): void
    {
        $friToSun = self::slots(Schedule::crontab('0 0 * * 5-7'), '2026-11-23T00:00:00Z', 3);
        $question = self::slots(Schedule::crontab('0 0 ? * mon'), '2026-11-23T00:00:00Z', 1);

        Assert::same(\array_map(static fn(int $slot): string => \gmdate('D', $slot), $friToSun), ['Fri', 'Sat', 'Sun']);
        Assert::same($question, [self::time('2026-11-30T00:00:00Z')]);
    }

    public function testIntervalAfterKeepsCountingFromItsAnchor(): void
    {
        $daily = Schedule::interval(1, 'day');
        $monthly = Schedule::interval(1, 'month');
        $zone = new \DateTimeZone('Europe/Berlin');

        Assert::same($daily->after(self::time('2026-03-28T01:30:00Z'), self::time('2026-03-29T01:30:00Z'), $zone), self::time('2026-03-30T00:30:00Z'));
        Assert::same($monthly->after(self::time('2026-01-31T10:00:00Z'), self::time('2026-02-28T10:00:00Z'), new \DateTimeZone('UTC')), self::time('2026-03-31T10:00:00Z'));
        Assert::same(Schedule::interval(10, 'minute')->after(self::time('2026-11-23T12:00:40Z'), self::time('2026-11-30T12:00:00Z'), $zone), self::time('2026-11-30T12:10:00Z'));
    }

    public function testIntervalUpToTenYears(): void
    {
        Assert::same(Schedule::interval(10, 'year')->describe(), 'every 10 years');
        Assert::same(Schedule::interval(5256000, 'minute')->describe(), 'every 5256000 minutes');
    }

    #[DataSet(['* * * *'], 'four fields')]
    #[DataSet(['* * * * * *'], 'six fields')]
    #[DataSet(['60 * * * *'], 'minute out of range')]
    #[DataSet(['0 24 * * *'], 'hour out of range')]
    #[DataSet(['0 0 0 * *'], 'day 0')]
    #[DataSet(['5-1 * * * *'], 'reversed range')]
    #[DataSet(['*/0 * * * *'], 'step 0')]
    #[DataSet(['0 0 * * mon-'], 'open range')]
    #[DataSet(['0 0 * * fri-sun'], 'weekday range that wraps')]
    #[DataSet(['a/5 * * * *'], 'letters with a step')]
    #[DataSet(['?/5 * * * *'], 'question mark with a step out of its fields')]
    #[DataSet(['0 0 L * *'], 'L')]
    #[DataSet(['@every 5m'], '@every')]
    #[DataSet(['@reboot'], '@reboot')]
    public function testRejectsInvalidCrontab(string $crontab): never
    {
        Expect::exception(\InvalidArgumentException::class);

        Schedule::crontab($crontab);
    }

    #[DataSet([10, 'minute', 'UTC', '2026-11-23T12:00:40Z', '2026-11-23T12:10:00Z'], 'from the minute of the check-in')]
    #[DataSet([2, 'hours', 'UTC', '2026-11-23T12:00:00Z', '2026-11-23T14:00:00Z'], 'plural unit')]
    #[DataSet([1, 'day', 'Europe/Berlin', '2026-03-28T02:00:00Z', '2026-03-29T01:00:00Z'], 'a day keeps the wall time')]
    #[DataSet([1, 'week', 'UTC', '2026-11-23T00:00:00Z', '2026-11-30T00:00:00Z'], 'a week')]
    #[DataSet([1, 'month', 'UTC', '2026-01-31T10:00:00Z', '2026-02-28T10:00:00Z'], 'a month from the 31st')]
    #[DataSet([1, 'year', 'UTC', '2028-02-29T10:00:00Z', '2029-02-28T10:00:00Z'], 'a year from a leap day')]
    public function testIntervalNextSlot(int $count, string $unit, string $zone, string $after, string $next): void
    {
        Assert::same(self::slots(Schedule::interval($count, $unit), $after, 1, $zone), [self::time($next)]);
    }

    public function testReadsSentrySchedule(): void
    {
        $crontab = Schedule::fromArray(['type' => 'crontab', 'value' => ' 0 3 * * * ']);
        $interval = Schedule::fromArray(['type' => 'interval', 'value' => '10', 'unit' => 'minute']);

        Assert::same($crontab->toArray(), ['type' => 'crontab', 'value' => '0 3 * * *']);
        Assert::same($interval->toArray(), ['type' => 'interval', 'value' => '10', 'unit' => 'minute']);
        Assert::same($crontab->describe(), 'crontab 0 3 * * *');
        Assert::same($interval->describe(), 'every 10 minutes');
    }

    #[DataSet([['type' => 'interval', 'value' => 0, 'unit' => 'minute']], 'interval 0')]
    #[DataSet([['type' => 'interval', 'value' => 1.5, 'unit' => 'minute']], 'fraction')]
    #[DataSet([['type' => 'interval', 'value' => 5, 'unit' => 'second']], 'seconds')]
    #[DataSet([['type' => 'interval', 'value' => 5]], 'no unit')]
    #[DataSet([['type' => 'interval', 'value' => 11, 'unit' => 'year']], 'over 10 years')]
    #[DataSet([['type' => 'interval', 'value' => '99999999999999999999', 'unit' => 'year']], 'a huge string')]
    #[DataSet([['type' => 'interval', 'value' => 1e20, 'unit' => 'day']], 'a huge float')]
    #[DataSet([['type' => 'interval', 'value' => 1e15, 'unit' => 'day']], 'too many days')]
    #[DataSet([['type' => 'interval', 'value' => \PHP_INT_MAX, 'unit' => 'minute']], 'the largest int')]
    #[DataSet([['type' => 'crontab', 'value' => 5]], 'crontab of a number')]
    #[DataSet([['type' => 'daily']], 'unknown type')]
    #[DataSet(['0 3 * * *'], 'not an object')]
    public function testRejectsInvalidSentrySchedule(mixed $schedule): never
    {
        Expect::exception(\InvalidArgumentException::class);

        Schedule::fromArray($schedule);
    }

    /**
     * @return list<int>
     */
    private static function slots(Schedule $schedule, string $after, int $count, string $zone = 'UTC'): array
    {
        $slots = [];
        $time = (float) self::time($after);
        for ($i = 0; $i < $count; ++$i) {
            $next = $schedule->next($time, new \DateTimeZone($zone));
            if ($next === null) {
                break;
            }
            $slots[] = $time = $next;
        }

        return $slots;
    }

    private static function time(string $iso): int
    {
        return (new \DateTimeImmutable($iso))->getTimestamp();
    }
}
