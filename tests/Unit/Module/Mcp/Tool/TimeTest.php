<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\Time;
use Buggregator\Trap\Module\Mcp\Tool\TimeRange;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Time::class)]
#[Covers(TimeRange::class)]
final class TimeTest
{
    private const NOW = 1_700_000_000.0;

    /** {@see self::NOW} in microseconds. */
    private const NOW_US = 1_700_000_000_000_000;

    #[DataSet(['30s', 30], 'seconds')]
    #[DataSet(['5m', 300], 'minutes')]
    #[DataSet(['2h', 7_200], 'hours')]
    #[DataSet(['1d', 86_400], 'days')]
    #[DataSet(['1w', 604_800], 'weeks')]
    #[DataSet(['1.5H', 5_400], 'fraction in upper case')]
    #[DataSet([' 10 s ', 10], 'spaces')]
    public function testDurationCountsBackFromNow(string $value, int $back): void
    {
        $expected = self::NOW_US - $back * 1_000_000;

        Assert::same(Time::fromArguments(['from' => $value], 'from', self::NOW), $expected);
        Assert::same(Time::fromArguments(['to' => $value], 'to', self::NOW, end: true), $expected);
    }

    #[DataSet(['2023-11-14T22:13:20Z', 1_700_000_000_000_000, 1_700_000_000_999_999], 'UTC')]
    #[DataSet(['2023-11-14T22:13:20', 1_700_000_000_000_000, 1_700_000_000_999_999], 'no offset is UTC')]
    #[DataSet(['2023-11-15T00:13:20+02:00', 1_700_000_000_000_000, 1_700_000_000_999_999], 'offset')]
    #[DataSet(['2023-11-14T22:13:20.250Z', 1_700_000_000_250_000, 1_700_000_000_250_999], 'milliseconds')]
    #[DataSet(['2023-11-14T22:13:20.250001Z', 1_700_000_000_250_001, 1_700_000_000_250_001], 'microseconds')]
    #[DataSet(['2023-11-14 22:13:20,5', 1_700_000_000_500_000, 1_700_000_000_599_999], 'space and comma')]
    #[DataSet(['2023-11-14T22:13Z', 1_699_999_980_000_000, 1_700_000_039_999_999], 'minute')]
    #[DataSet(['2023-11-14', 1_699_920_000_000_000, 1_700_006_399_999_999], 'date')]
    public function testIso8601SpansItsLastUnit(string $value, int $start, int $end): void
    {
        Assert::same(Time::fromArguments(['from' => $value], 'from', self::NOW), $start);
        Assert::same(Time::fromArguments(['to' => $value], 'to', self::NOW, end: true), $end);
    }

    public function testMissingIsNull(): void
    {
        Assert::null(Time::fromArguments([], 'from', self::NOW));
    }

    #[DataSet(['5 minutes'], 'words')]
    #[DataSet(['tomorrow'], 'relative date words')]
    #[DataSet(['-5m'], 'negative')]
    #[DataSet(['5y'], 'unknown unit')]
    #[DataSet(['2023-02-30'], 'no such day')]
    #[DataSet(['2023-13-01T00:00:00Z'], 'no such month')]
    #[DataSet([''], 'empty')]
    public function testRejectsMalformed(string $value): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage("`from` must be ISO 8601, such as `2026-11-16T10:00:00Z`, or a duration back from now, such as `30s`, `5m`, `2h`, `1d`; got `$value`.");

        Time::fromArguments(['from' => $value], 'from', self::NOW);
    }

    #[DataSet(['5218w'], 'just over 100 years')]
    #[DataSet(['100000000w'], 'microseconds beyond an integer')]
    #[DataSet([\PHP_INT_MAX . '0s'], 'more digits than an integer')]
    public function testRejectsTooLongDuration(string $value): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage("`to` must be a duration of at most 100 years, such as `5200w`; got `$value`.");

        Time::fromArguments(['to' => $value], 'to', self::NOW, end: true);
    }

    public function testAcceptsHundredYears(): void
    {
        Assert::same(Time::fromArguments(['from' => '5217w'], 'from', self::NOW), self::NOW_US - 5217 * 604_800_000_000);
    }

    public function testRejectsNotString(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`from` must be a string.');

        Time::fromArguments(['from' => 300], 'from', self::NOW);
    }

    public function testRange(): void
    {
        $range = Time::range(['from' => '1h', 'to' => '5m'], self::NOW);

        Assert::same([$range->from, $range->to], [self::NOW_US - 3_600_000_000, self::NOW_US - 300_000_000]);
        Assert::true(Time::range([], self::NOW)->isUnbounded());
    }

    public function testRangeOfOneDay(): void
    {
        $range = Time::range(['from' => '2023-11-14', 'to' => '2023-11-14'], self::NOW);

        Assert::true($range->contains(1_699_920_000.0));
        Assert::true($range->contains(1_700_006_399.9999));
        Assert::false($range->contains(1_700_006_400.0));
        Assert::false($range->contains(1_699_919_999.9999));
    }

    public function testRangeRejectsFromAfterTo(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`from` is later than `to`.');

        Time::range(['from' => '5m', 'to' => '1h'], self::NOW);
    }

    #[DataSet([1_700_000_000.5, '2023-11-14T22:13:20.500+00:00'], 'half a second')]
    #[DataSet([1_700_000_000.7379, '2023-11-14T22:13:20.737+00:00'], 'cut to milliseconds')]
    public function testFormat(float $timestamp, string $expected): void
    {
        Assert::same(Time::format($timestamp), $expected);
    }

    #[DataSet([1_700_000_000.737], 'whole milliseconds')]
    #[DataSet([1_700_000_000.7379], 'microseconds after')]
    #[DataSet([1_700_000_000.0009], 'microseconds of the first millisecond')]
    #[DataSet([1_763_287_200.736999], 'microsecond before a millisecond')]
    public function testListedTimeRoundTripsAsBothBounds(float $timestamp): void
    {
        $listed = Time::format($timestamp);

        $range = Time::range(['from' => $listed, 'to' => $listed], self::NOW);

        Assert::true($range->contains($timestamp), "`$listed` takes the time it was listed for.");
    }
}
