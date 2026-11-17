<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Caller;
use Buggregator\Trap\Module\Mcp\Tool\ListEvents;
use Buggregator\Trap\Module\Mcp\Tool\Scan;
use Buggregator\Trap\Module\Mcp\Tool\WaitForEvents;
use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Module\Mcp\View\TextCache;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\JsonSchema;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\ScriptedView;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WaitForEvents::class)]
#[Covers(Caller::class)]
final class WaitForEventsTest
{
    public function testReturnsTheEventArrivingWhileItWaits(): void
    {
        $trap = new Trap();
        $trap->add(Trap::dump('before'));
        $fiber = self::wait($trap, []);

        self::spin($fiber, 5);
        $waiting = $fiber->isSuspended();
        $arrived = $trap->add(Trap::dump('arrived'));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::true($waiting);
        Assert::same(self::summaries($result), ['arrived']);
        Assert::false($result['timed_out']);
        Assert::false($result['has_more']);
        Assert::same($result['cursor'], $trap->store->id . ':' . $arrived->seq);
        Assert::same(JsonSchema::errors($result, $trap->get(WaitForEvents::class)->outputSchema()), []);
    }

    public function testWaitsForTheCount(): void
    {
        $trap = new Trap();
        $fiber = self::wait($trap, ['count' => 2]);

        $trap->add(Trap::dump('first'));
        $fiber->resume();
        $waiting = $fiber->isSuspended();
        $trap->add(Trap::dump('second'));
        $fiber->resume();

        Assert::true($waiting);
        Assert::same(self::summaries($fiber->getReturn()), ['first', 'second']);
        Assert::false($fiber->getReturn()['timed_out']);
    }

    public function testMoreEventsThanTheCountAreLeftToTheCursor(): void
    {
        $trap = new Trap();
        $fiber = self::wait($trap, []);

        $trap->add(Trap::dump('first'));
        $trap->add(Trap::dump('second'));
        $fiber->resume();
        $result = $fiber->getReturn();
        $next = $trap->get(ListEvents::class)->call(['since' => $result['cursor']]);

        Assert::same(self::summaries($result), ['first']);
        Assert::true($result['has_more']);
        Assert::same(self::summaries($next), ['second']);
    }

    public function testOnlyMatchingEventsCount(): void
    {
        $trap = new Trap();
        $fiber = self::wait($trap, ['type' => 'monolog', 'include' => 'text']);

        $trap->add(Trap::dump('not this'));
        $fiber->resume();
        $waiting = $fiber->isSuspended();
        $trap->add(Trap::log('this one'));
        $fiber->resume();
        $events = $fiber->getReturn()['events'];

        Assert::true($waiting);
        Assert::same(\array_column($events, 'type'), ['monolog']);
        Assert::string($events[0]['text'])->contains('this one');
    }

    public function testTimeoutReturnsWhatArrived(): void
    {
        $trap = new Trap();
        $fiber = self::wait($trap, ['count' => 3, 'timeout' => 0.05]);

        $trap->add(Trap::dump('only one'));
        $resumes = self::finish($fiber);
        $result = $fiber->getReturn();

        Assert::same(self::summaries($result), ['only one']);
        Assert::true($result['timed_out']);
        Assert::false($result['has_more']);
        Assert::int($resumes)->greaterThan(0);
    }

    public function testTimeoutWithoutEventsIsNoError(): void
    {
        $trap = new Trap();
        $fiber = self::wait($trap, ['timeout' => 0]);

        $result = $fiber->getReturn();

        Assert::same($result['events'], []);
        Assert::true($result['timed_out']);
        Assert::same($result['cursor'], $trap->store->id . ':0');
    }

    public function testSinceCatchesTheEventsSentBeforeTheCall(): void
    {
        $trap = new Trap();
        $cursor = $trap->get(ListEvents::class)->call([])['cursor'];
        $trap->add(Trap::dump('early'));

        $fiber = self::wait($trap, ['since' => $cursor]);
        $missed = self::wait($trap, ['timeout' => 0]);

        Assert::true($fiber->isTerminated(), 'An event after the cursor needs no waiting.');
        Assert::same(self::summaries($fiber->getReturn()), ['early']);
        Assert::same($missed->getReturn()['events'], [], 'Without `since` it waits for the events after the call.');
    }

    public function testConcurrentWaitsGetTheirOwnEvents(): void
    {
        $trap = new Trap();
        $logs = self::wait($trap, ['type' => 'monolog']);
        $dumps = self::wait($trap, ['type' => 'var-dump', 'count' => 2]);

        $trap->add(Trap::dump('dump 1'));
        $trap->add(Trap::log('log'));
        $logs->resume();
        $dumps->resume();
        $dumpsWaiting = $dumps->isSuspended();
        $trap->add(Trap::dump('dump 2'));
        $dumps->resume();

        Assert::same(self::summaries($logs->getReturn()), ['ERROR app: log']);
        Assert::true($dumpsWaiting);
        Assert::same(self::summaries($dumps->getReturn()), ['dump 1', 'dump 2']);
    }

    public function testResumesWithoutChangesCheckNothing(): void
    {
        $trap = Trap::scripted();
        // No text is kept: every check of an event renders it again
        $trap->container->set(new TextCache($trap->get(EventViews::class), $trap->store, maxBytes: 0));
        $trap->store->add(ScriptedView::event('5a4f8e1c-0000-4000-8000-000000000001', 'needle 1', 1.0));
        $trap->store->add(ScriptedView::event('5a4f8e1c-0000-4000-8000-000000000002', 'needle 2', 2.0));
        $fiber = self::wait($trap, ['since' => $trap->store->id . ':0', 'search' => 'needle', 'count' => 3]);

        $checked = $trap->view()->renders;
        self::spin($fiber, 100);
        $idle = $trap->view()->renders;
        $trap->store->add(ScriptedView::event('5a4f8e1c-0000-4000-8000-000000000003', 'needle 3', 3.0));
        $fiber->resume();

        Assert::same($checked, 2);
        Assert::same($idle, 2);
        Assert::same(\count($fiber->getReturn()['events']), 3);
        Assert::same($trap->view()->renders, 3);
    }

    public function testSlowSearchLetsOtherFibersRun(): void
    {
        $trap = Trap::scripted();
        $trap->view()->delay = 25_000;
        $fiber = self::wait($trap, ['search' => 'needle', 'count' => 3]);

        for ($i = 1; $i <= 3; ++$i) {
            $trap->store->add(ScriptedView::event("5a4f8e1c-0000-4000-8000-00000000000$i", "needle $i", (float) $i));
        }
        $fiber->resume();
        $suspended = $fiber->isSuspended();
        self::finish($fiber);

        Assert::true($suspended, 'The scan suspends between the slow texts.');
        Assert::same(\count($fiber->getReturn()['events']), 3);
    }

    public function testStopsWhenTheClientDisconnects(): void
    {
        $trap = new Trap();
        $tool = $trap->get(WaitForEvents::class);
        $caller = $trap->get(Caller::class);
        $gone = false;
        $disconnected = static function () use (&$gone): bool {
            return $gone;
        };
        $fiber = new \Fiber(static fn(): array => $caller->connected(
            $disconnected,
            static fn(): array => $tool->call([]),
        ));

        $fiber->start();
        $waiting = $fiber->isSuspended();
        $gone = true;
        $fiber->resume();

        Assert::true($waiting);
        Assert::true($fiber->getReturn()['timed_out']);
        Assert::false($caller->isDisconnected(), 'The binding ends with the call.');
    }

    public function testOutsideFiberChecksOnce(): void
    {
        $trap = new Trap();
        $cursor = $trap->get(ListEvents::class)->call([])['cursor'];
        $trap->add(Trap::dump('there'));

        $result = $trap->get(WaitForEvents::class)->call(['since' => $cursor, 'count' => 2]);

        Assert::same(self::summaries($result), ['there']);
        Assert::true($result['timed_out']);
    }

    public function testTimeoutOverTheLimitFails(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`timeout` must be a number from 0 to 50.');

        (new Trap())->get(WaitForEvents::class)->call(['timeout' => 51]);
    }

    public function testOutsideFiberStopsWhereTheScanRanOutOfTime(): void
    {
        $trap = Trap::scripted();
        $trap->container->set(new Scan(0.0));
        $trap->view()->delay = 5_000;
        $records = [];
        for ($i = 1; $i <= 3; ++$i) {
            $records[] = $trap->store->add(ScriptedView::event("5a4f8e1c-0000-4000-8000-00000000000$i", "text $i", (float) $i));
        }

        $result = $trap->get(WaitForEvents::class)->call(['since' => $trap->store->id . ':0', 'search' => 'text 3']);

        Assert::same($result['events'], []);
        Assert::true($result['has_more']);
        Assert::true($result['timed_out']);
        Assert::same($result['cursor'], $trap->store->id . ':1');
    }

    public function testEventUpdatedWhileWaitingIsListedOnceAtItsLatestChange(): void
    {
        $trap = Trap::scripted();
        $fiber = self::wait($trap, ['count' => 3, 'include' => 'text']);

        $trap->store->add(ScriptedView::event(self::uuid(1), 'first', 1.0));
        $fiber->resume();
        $trap->store->add(ScriptedView::event(self::uuid(2), 'second', 2.0));
        $fiber->resume();
        // As Ray does when the application updates a message it has sent
        $trap->store->add(ScriptedView::event(self::uuid(1), 'first, updated', 1.0));
        $fiber->resume();
        $waiting = $fiber->isSuspended();
        $trap->store->add(ScriptedView::event(self::uuid(3), 'third', 3.0));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::true($waiting, 'The update counts no new event.');
        Assert::same(\array_column($result['events'], 'id'), [self::uuid(2), self::uuid(1), self::uuid(3)]);
        Assert::same(\array_column($result['events'], 'text'), ['second', 'first, updated', 'third']);
        Assert::false($result['timed_out']);
        Assert::false($result['has_more']);
    }

    public function testUpdatesTakingTheLimitOfAPageAreCheckedPast(): void
    {
        $trap = Trap::scripted();
        $fiber = self::wait($trap, ['count' => 2]);

        $trap->store->add(ScriptedView::event(self::uuid(1), 'first', 1.0));
        $fiber->resume();
        $trap->store->add(ScriptedView::event(self::uuid(1), 'first, updated', 1.0));
        $trap->store->add(ScriptedView::event(self::uuid(2), 'second', 2.0));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::same(\array_column($result['events'], 'id'), [self::uuid(1), self::uuid(2)]);
        Assert::false($result['timed_out']);
        Assert::false($result['has_more']);
    }

    public function testZeroTimeoutGetsTheScanBudgetForTheBacklog(): void
    {
        $trap = Trap::scripted();
        $trap->view()->delay = 2_000;
        for ($i = 1; $i <= 5; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), "text $i", (float) $i));
        }
        $arguments = ['since' => $trap->store->id . ':0', 'search' => 'text 5'];

        $fiber = self::wait($trap, [...$arguments, 'timeout' => 0]);
        self::finish($fiber);
        $result = $fiber->getReturn();
        $listed = $trap->get(ListEvents::class)->call($arguments);

        Assert::same(\array_column($result['events'], 'id'), [self::uuid(5)]);
        Assert::same(\array_column($result['events'], 'id'), \array_column($listed['events'], 'id'));
        Assert::false($result['timed_out']);
    }

    public function testTextsFillingTheResponseAcrossPagesLeaveTheRestToTheCursor(): void
    {
        $trap = Trap::scripted();
        $perEvent = ListEvents::MAX_TEXT_LENGTH;
        $fitting = \intdiv(ListEvents::MAX_TOTAL_TEXT_LENGTH, $perEvent);
        for ($i = 1; $i <= $fitting; ++$i) {
            $last = $trap->store->add(ScriptedView::event(self::uuid($i), \str_repeat('x', $perEvent), (float) $i));
        }
        $fiber = self::wait($trap, [
            'since' => $trap->store->id . ':0',
            'count' => $fitting + 2,
            'include' => 'text',
            'max_length' => $perEvent,
        ]);

        $waiting = $fiber->isSuspended();
        $trap->store->add(ScriptedView::event(self::uuid(99), 'over the cap', 99.0));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::true($waiting, 'The texts of the first page fit.');
        Assert::count($result['events'], $fitting);
        Assert::true($result['has_more']);
        Assert::false($result['timed_out']);
        Assert::same($result['cursor'], $trap->store->id . ':' . $last->seq);
    }

    public function testTextsOfReplacedCopiesTakeNoRoom(): void
    {
        $trap = Trap::scripted();
        $perEvent = ListEvents::MAX_TEXT_LENGTH;
        $updates = \intdiv(ListEvents::MAX_TOTAL_TEXT_LENGTH, $perEvent) + 5;
        $fiber = self::wait($trap, ['count' => 2, 'timeout' => 1, 'include' => 'text', 'max_length' => $perEvent]);

        for ($i = 0; $i < $updates && $fiber->isSuspended(); ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid(1), \str_repeat((string) ($i % 10), $perEvent), 1.0));
            $fiber->resume();
        }
        $waiting = $fiber->isSuspended();
        self::finish($fiber);
        $result = $fiber->getReturn();

        Assert::true($waiting, 'The updates of one event leave room for the second one.');
        Assert::same(\array_column($result['events'], 'id'), [self::uuid(1)]);
        Assert::same($result['events'][0]['text'], \str_repeat((string) (($updates - 1) % 10), $perEvent));
        Assert::true($result['timed_out']);
        Assert::false($result['has_more']);
    }

    public function testUpdateOfAnEventFillingTheTextsIsListedAtItsLatestText(): void
    {
        $trap = Trap::scripted();
        $perEvent = ListEvents::MAX_TEXT_LENGTH;
        $fitting = \intdiv(ListEvents::MAX_TOTAL_TEXT_LENGTH, $perEvent);
        for ($i = 1; $i <= $fitting; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), \str_repeat('x', $perEvent), (float) $i));
        }
        $fiber = self::wait($trap, [
            'since' => $trap->store->id . ':0',
            'count' => $fitting + 2,
            'include' => 'text',
            'max_length' => $perEvent,
        ]);

        $trap->store->add(ScriptedView::event(self::uuid(1), \str_repeat('y', $perEvent), 1.0));
        $fiber->resume();
        $waiting = $fiber->isSuspended();
        $last = $trap->store->add(ScriptedView::event(self::uuid(99), 'over the cap', 99.0));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::true($waiting, 'The update takes the room of the old copy.');
        Assert::count($result['events'], $fitting);
        Assert::same(\end($result['events'])['id'], self::uuid(1));
        Assert::same(\end($result['events'])['text'], \str_repeat('y', $perEvent));
        Assert::true($result['has_more']);
        Assert::false($result['timed_out']);
        Assert::same($result['cursor'], $trap->store->id . ':' . ($last->seq - 1));
    }

    public function testNewEventAndUpdateOnOnePageShareTheRoomOfTheTexts(): void
    {
        $trap = Trap::scripted();
        $perEvent = ListEvents::MAX_TEXT_LENGTH;
        $fitting = \intdiv(ListEvents::MAX_TOTAL_TEXT_LENGTH, $perEvent);
        for ($i = 1; $i < $fitting; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), \str_repeat('x', $perEvent), (float) $i));
        }
        $fiber = self::wait($trap, [
            'since' => $trap->store->id . ':0',
            'count' => $fitting + 1,
            'timeout' => 1,
            'include' => 'text',
            'max_length' => $perEvent,
        ]);

        $trap->store->add(ScriptedView::event(self::uuid(99), \str_repeat('b', $perEvent), 99.0));
        $trap->store->add(ScriptedView::event(self::uuid(1), \str_repeat('y', $perEvent), 1.0));
        $fiber->resume();
        $waiting = $fiber->isSuspended();
        self::finish($fiber);
        $result = $fiber->getReturn();

        Assert::true($waiting, 'Both changes fit, and one more event is awaited.');
        Assert::same(
            \array_slice(\array_column($result['events'], 'id'), -2),
            [self::uuid(99), self::uuid(1)],
        );
        Assert::same(\end($result['events'])['text'], \str_repeat('y', $perEvent));
        Assert::count($result['events'], $fitting);
        Assert::false($result['has_more']);
        Assert::true($result['timed_out']);
    }

    public function testRestLeftUncheckedIsCheckedWithoutWaitingForChanges(): void
    {
        $trap = Trap::scripted();
        // Each pass checks one record only
        $trap->container->set(new Scan(0.0));
        for ($i = 1; $i <= 3; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), "text $i", (float) $i));
        }
        $cursor = $trap->store->cursor();

        $fiber = self::wait($trap, ['since' => $trap->store->id . ':0', 'search' => 'text 3']);
        self::spin($fiber, 10);

        Assert::true($fiber->isTerminated());
        Assert::same($trap->store->cursor(), $cursor);
        Assert::same(\array_column($fiber->getReturn()['events'], 'id'), [self::uuid(3)]);
        Assert::false($fiber->getReturn()['timed_out']);
    }

    /**
     * @param int<1, 99> $i
     */
    private static function uuid(int $i): string
    {
        return \sprintf('5a4f8e1c-0000-4000-8000-0000000000%02d', $i);
    }

    /**
     * Starts the tool in a fiber: it returns at once or waits suspended.
     */
    private static function wait(Trap $trap, array $arguments): \Fiber
    {
        $tool = $trap->get(WaitForEvents::class);
        $fiber = new \Fiber(static fn(): array => $tool->call($arguments));
        $fiber->start();

        return $fiber;
    }

    private static function spin(\Fiber $fiber, int $times): void
    {
        for ($i = 0; $i < $times && !$fiber->isTerminated(); ++$i) {
            $fiber->resume();
        }
    }

    /**
     * @return int<0, max> Times the fiber was resumed.
     */
    private static function finish(\Fiber $fiber): int
    {
        $resumes = 0;
        while (!$fiber->isTerminated()) {
            $fiber->resume();
            ++$resumes;
        }

        return $resumes;
    }

    /**
     * @return list<string>
     */
    private static function summaries(array $result): array
    {
        return \array_column($result['events'], 'summary');
    }
}
