<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\CollectMemo;
use Buggregator\Trap\Module\Mcp\Tool\EventFilter;
use Buggregator\Trap\Module\Mcp\Tool\EventFilterArguments;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Test;

final class CollectMemoTest
{
    #[Test]
    public function completePassDropsVerdictsOfRecordsItDidNotMeet(): void
    {
        $memo = new CollectMemo();
        $filter = self::filter();
        $kept = self::record('a');
        $gone = self::record('b');

        $memo->matches($kept, $filter);
        $memo->matches($gone, $filter);
        $memo->pass();
        $memo->matches($kept, $filter);
        $memo->pass();

        Assert::same(\array_keys(self::verdicts($memo)), ['a']);
    }

    #[Test]
    public function passCutShortKeepsVerdictsOfRecordsItDidNotReach(): void
    {
        $memo = new CollectMemo();
        $filter = self::filter();

        $memo->matches(self::record('a'), $filter);
        $memo->matches(self::record('b'), $filter);
        $memo->pass();
        $memo->matches(self::record('a'), $filter);
        $memo->pass(complete: false);

        Assert::same(\array_keys(self::verdicts($memo)), ['a', 'b']);
    }

    private static function filter(): EventFilter
    {
        return (new Trap())->get(EventFilterArguments::class)->parse([]);
    }

    private static function record(string $uuid): Record
    {
        return new Record(new Event($uuid, 'monolog', [], 1.0), 1, []);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    private static function verdicts(CollectMemo $memo): array
    {
        /** @var array<string, array{int, bool}> */
        return (static fn(): array => $memo->matches)->bindTo(null, CollectMemo::class)();
    }
}
