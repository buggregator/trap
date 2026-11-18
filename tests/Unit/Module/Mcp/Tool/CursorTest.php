<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\Cursor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Cursor::class)]
final class CursorTest
{
    private const STORE = 'a1b2';

    public function testEncodedCursorReadsBack(): void
    {
        $cursor = Cursor::encode(self::STORE, 7);

        Assert::same($cursor, 'a1b2:7');
        Assert::same(Cursor::since(['since' => $cursor], self::STORE, 10), 7);
    }

    #[DataSet(['a1b2:0', 0], 'zero')]
    #[DataSet(['a1b2:10', 10], 'last')]
    #[DataSet(['a1b2:11', 0], 'ahead of the store')]
    #[DataSet(['ffff:5', 0], 'other process')]
    #[DataSet(['a1b2', 0], 'no sequence')]
    #[DataSet(['a1b2:', 0], 'empty sequence')]
    #[DataSet(['a1b2:-1', 0], 'negative')]
    #[DataSet(['a1b2:5:6', 0], 'extra part')]
    #[DataSet(['', 0], 'empty')]
    public function testSince(string $since, int $expected): void
    {
        Assert::same(Cursor::since(['since' => $since], self::STORE, 10), $expected);
    }

    public function testMissingIsEverything(): void
    {
        Assert::same(Cursor::since([], self::STORE, 10), 0);
    }

    public function testRejectsNotString(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`since` must be a string.');

        Cursor::since(['since' => 5], self::STORE, 10);
    }
}
