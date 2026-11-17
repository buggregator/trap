<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\Time;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Time::class)]
final class TimeTest
{
    private const NOW = 1_700_000_000.0;

    #[DataSet(['30s', 30.0], 'seconds')]
    #[DataSet(['5m', 300.0], 'minutes')]
    #[DataSet(['2h', 7_200.0], 'hours')]
    #[DataSet(['1d', 86_400.0], 'days')]
    #[DataSet(['1w', 604_800.0], 'weeks')]
    #[DataSet(['1.5H', 5_400.0], 'fraction in upper case')]
    #[DataSet([' 10 s ', 10.0], 'spaces')]
    public function testDurationCountsBackFromNow(string $value, float $back): void
    {
        Assert::same(Time::fromArguments(['from' => $value], 'from', self::NOW), self::NOW - $back);
    }

    #[DataSet(['2023-11-14T22:13:20Z', 1_700_000_000.0], 'UTC')]
    #[DataSet(['2023-11-14T22:13:20', 1_700_000_000.0], 'no offset is UTC')]
    #[DataSet(['2023-11-15T00:13:20+02:00', 1_700_000_000.0], 'offset')]
    #[DataSet(['2023-11-14T22:13:20.250Z', 1_700_000_000.25], 'fraction')]
    #[DataSet(['2023-11-14 22:13:20,5', 1_700_000_000.5], 'space and comma')]
    #[DataSet(['2023-11-14', 1_699_920_000.0], 'date')]
    public function testIso8601(string $value, float $expected): void
    {
        Assert::same(Time::fromArguments(['to' => $value], 'to', self::NOW), $expected);
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

    public function testRejectsNotString(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`from` must be a string.');

        Time::fromArguments(['from' => 300], 'from', self::NOW);
    }

    public function testRange(): void
    {
        Assert::same(Time::range(['from' => '1h', 'to' => '5m'], self::NOW), [self::NOW - 3_600.0, self::NOW - 300.0]);
        Assert::same(Time::range([], self::NOW), [null, null]);
    }

    public function testRangeRejectsFromAfterTo(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`from` is later than `to`.');

        Time::range(['from' => '5m', 'to' => '1h'], self::NOW);
    }

    public function testFormat(): void
    {
        Assert::same(Time::format(1_700_000_000.5), '2023-11-14T22:13:20.500+00:00');
    }
}
