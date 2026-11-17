<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\ClearEvents;
use Buggregator\Trap\Module\Mcp\Tool\ListEvents;
use Buggregator\Trap\Module\Mcp\Tool\Scan;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\JsonSchema;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\ScriptedView;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Scan::class)]
#[Covers(ListEvents::class)]
#[Covers(ClearEvents::class)]
final class ScanTest
{
    /** Microseconds to render each text: longer than the slice of the scan. */
    private const SLOW = 25_000;

    public function testSearchInFiberLetsOtherFibersRun(): void
    {
        [$trap, $records] = self::slowTrap(4);
        $tool = $trap->get(ListEvents::class);
        $fiber = new \Fiber(static fn(): array => $tool->call(['search' => 'needle']));

        $fiber->start();
        $suspended = $fiber->isSuspended();
        $suspensions = self::finish($fiber);

        Assert::true($suspended);
        Assert::int($suspensions)->greaterThanOrEqual(3);
        Assert::same(self::ids($fiber->getReturn()), self::uuids($records));
    }

    public function testSearchOutsideFiberRunsThrough(): void
    {
        [$trap, $records] = self::slowTrap(3);

        $result = $trap->get(ListEvents::class)->call(['search' => 'needle']);

        Assert::same(self::ids($result), self::uuids($records));
        Assert::false($result['has_more']);
    }

    public function testEventsChangedDuringTheScanAreLeftToTheNextCall(): void
    {
        [$trap, [$first, $second, $third]] = self::slowTrap(3);
        $tool = $trap->get(ListEvents::class);
        $fiber = new \Fiber(static fn(): array => $tool->call(['search' => 'needle']));

        $fiber->start();
        $trap->store->delete($third->event->uuid);
        $trap->store->add($second->event);
        self::finish($fiber);
        $result = $fiber->getReturn();

        Assert::same(self::ids($result), [$first->event->uuid]);
        Assert::false($result['has_more']);
        Assert::same(self::ids($tool->call(['since' => $result['cursor']])), [$second->event->uuid]);
    }

    public function testClearDeletesOnlyTheEventsUnchangedSinceMatched(): void
    {
        [$trap, [$first, $second, $third]] = self::slowTrap(3);
        $tool = $trap->get(ClearEvents::class);
        $fiber = new \Fiber(static fn(): array => $tool->call(['search' => 'needle']));

        $fiber->start();
        // The first one is matched already, and changes before the deletion
        $updated = $trap->store->add($first->event);
        self::finish($fiber);

        Assert::same($fiber->getReturn(), ['removed' => 2]);
        Assert::same($trap->store->record($first->event->uuid), $updated);
        Assert::null($trap->store->record($second->event->uuid));
        Assert::null($trap->store->record($third->event->uuid));
    }

    public function testListingOutOfTimeGoesOnWithTheCursor(): void
    {
        [$trap, $records] = self::slowTrap(6, budget: 0.04);
        $tool = $trap->get(ListEvents::class);

        $first = $tool->call(['search' => 'needle']);
        $listed = self::ids($first);
        $calls = 1;
        $result = $first;
        while ($result['has_more']) {
            $result = $tool->call(['search' => 'needle', 'since' => $result['cursor']]);
            $listed = [...$listed, ...self::ids($result)];
            ++$calls;
        }

        Assert::true($first['has_more']);
        Assert::int(\count($first['events']))->greaterThanOrEqual(1)->lessThan(6);
        Assert::int($calls)->greaterThan(1);
        Assert::same($listed, self::uuids($records));
        Assert::same($result['cursor'], $trap->store->id . ':6');
    }

    public function testNewestOutOfTimeKeepsTheCursorAtTheLatestChange(): void
    {
        [$trap] = self::slowTrap(6, budget: 0.04);

        $result = $trap->get(ListEvents::class)->call(['search' => 'needle', 'order' => 'newest']);

        Assert::true($result['has_more']);
        Assert::int(\count($result['events']))->lessThan(6);
        Assert::same($result['cursor'], $trap->store->id . ':6');
    }

    public function testClearOutOfTimeDeletesWhatItFound(): void
    {
        [$trap] = self::slowTrap(6, budget: 0.04);
        $tool = $trap->get(ClearEvents::class);

        $first = $tool->call(['search' => 'needle']);
        $removed = $first['removed'];
        do {
            $result = $tool->call(['search' => 'needle']);
            $removed += $result['removed'];
        } while (isset($result['incomplete']));

        Assert::true($first['incomplete'] ?? false);
        Assert::int($first['removed'])->greaterThanOrEqual(1)->lessThan(6);
        Assert::same($removed, 6);
        Assert::count($trap->store, 0);
        Assert::same(JsonSchema::errors($first, $tool->outputSchema()), []);
    }

    public function testEveryCallChecksOneEventAtLeast(): void
    {
        [$trap, $records] = self::slowTrap(2, budget: 0.0);
        $tool = $trap->get(ListEvents::class);

        $first = $tool->call(['search' => 'needle']);
        $second = $tool->call(['search' => 'needle', 'since' => $first['cursor']]);

        Assert::same(self::ids($first), [$records[0]->event->uuid]);
        Assert::same(self::ids($second), [$records[1]->event->uuid]);
    }

    /**
     * @param positive-int $count
     *
     * @return array{Trap, list<Record>} Events whose texts contain `needle` and take {@see self::SLOW} to render.
     */
    private static function slowTrap(int $count, float $budget = Scan::BUDGET): array
    {
        $trap = Trap::scripted();
        $trap->container->set(new Scan($budget));
        $trap->view()->delay = self::SLOW;
        $records = [];
        for ($i = 1; $i <= $count; ++$i) {
            $uuid = \sprintf('5a4f8e1c-0000-4000-8000-%012d', $i);
            $records[] = $trap->store->add(ScriptedView::event($uuid, "text $i with a needle", (float) $i));
        }

        return [$trap, $records];
    }

    /**
     * @return int<0, max> Times the fiber suspended again.
     */
    private static function finish(\Fiber $fiber): int
    {
        $suspensions = 0;
        while (!$fiber->isTerminated()) {
            $fiber->resume();
            $fiber->isTerminated() or ++$suspensions;
        }

        return $suspensions;
    }

    /**
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        return \array_column($result['events'], 'id');
    }

    /**
     * @param list<Record> $records
     *
     * @return list<string>
     */
    private static function uuids(array $records): array
    {
        return \array_map(static fn(Record $record): string => $record->event->uuid, $records);
    }
}
