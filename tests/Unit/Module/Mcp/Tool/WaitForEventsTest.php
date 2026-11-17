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
        $since = self::cursor($trap);
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
        self::assertListed($trap, ['since' => $since], $result);
    }

    public function testWaitsForTheCount(): void
    {
        $trap = new Trap();
        $since = self::cursor($trap);
        $fiber = self::wait($trap, ['count' => 2]);

        $trap->add(Trap::dump('first'));
        $fiber->resume();
        $waiting = $fiber->isSuspended();
        $trap->add(Trap::dump('second'));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::true($waiting);
        Assert::same(self::summaries($result), ['first', 'second']);
        Assert::false($result['timed_out']);
        self::assertListed($trap, ['since' => $since, 'count' => 2], $result);
    }

    public function testMoreEventsThanTheCountAreLeftToTheCursor(): void
    {
        $trap = new Trap();
        $since = self::cursor($trap);
        $fiber = self::wait($trap, []);

        $trap->add(Trap::dump('first'));
        $trap->add(Trap::dump('second'));
        $fiber->resume();
        $result = $fiber->getReturn();
        $next = $trap->get(ListEvents::class)->call(['since' => $result['cursor']]);

        Assert::same(self::summaries($result), ['first']);
        Assert::true($result['has_more']);
        Assert::same(self::summaries($next), ['second']);
        self::assertListed($trap, ['since' => $since], $result);
    }

    public function testOnlyMatchingEventsCount(): void
    {
        $trap = new Trap();
        $since = self::cursor($trap);
        $arguments = ['type' => 'monolog', 'include' => 'text'];
        $fiber = self::wait($trap, $arguments);

        $trap->add(Trap::dump('not this'));
        $fiber->resume();
        $waiting = $fiber->isSuspended();
        $trap->add(Trap::log('this one'));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::true($waiting);
        Assert::same(\array_column($result['events'], 'type'), ['monolog']);
        Assert::string($result['events'][0]['text'])->contains('this one');
        self::assertListed($trap, [...$arguments, 'since' => $since], $result);
    }

    public function testTimeoutReturnsWhatArrived(): void
    {
        $trap = new Trap();
        $since = self::cursor($trap);
        $fiber = self::wait($trap, ['count' => 3, 'timeout' => 0.05]);

        $trap->add(Trap::dump('only one'));
        $resumes = self::finish($fiber);
        $result = $fiber->getReturn();

        Assert::same(self::summaries($result), ['only one']);
        Assert::true($result['timed_out']);
        Assert::false($result['has_more']);
        Assert::int($resumes)->greaterThan(0);
        self::assertListed($trap, ['since' => $since, 'count' => 3], $result);
    }

    public function testTimeoutWithoutEventsIsNoError(): void
    {
        $trap = new Trap();
        $fiber = self::wait($trap, ['timeout' => 0]);

        $result = $fiber->getReturn();

        Assert::same($result['events'], []);
        Assert::true($result['timed_out']);
        Assert::same($result['cursor'], $trap->store->id . ':0');
        self::assertListed($trap, ['since' => $trap->store->id . ':0'], $result);
    }

    public function testZeroTimeoutReturnsTheBacklog(): void
    {
        $trap = new Trap();
        $trap->add(Trap::dump('first'));
        $trap->add(Trap::dump('second'));
        $arguments = ['since' => $trap->store->id . ':0', 'count' => 3, 'timeout' => 0];

        $fiber = self::wait($trap, $arguments);
        $result = $fiber->getReturn();

        Assert::same(self::summaries($result), ['first', 'second']);
        Assert::true($result['timed_out']);
        Assert::false($result['has_more']);
        self::assertListed($trap, $arguments, $result);
    }

    public function testZeroTimeoutGetsTheScanBudgetForTheBacklog(): void
    {
        $trap = Trap::scripted();
        $trap->view()->delay = 2_000;
        for ($i = 1; $i <= 5; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), "text $i", (float) $i));
        }
        $arguments = ['since' => $trap->store->id . ':0', 'search' => 'text 5', 'timeout' => 0];

        $fiber = self::wait($trap, $arguments);
        self::finish($fiber);
        $result = $fiber->getReturn();

        Assert::same(\array_column($result['events'], 'id'), [self::uuid(5)]);
        Assert::false($result['timed_out']);
        self::assertListed($trap, $arguments, $result);
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
        self::assertListed($trap, ['since' => $cursor], $fiber->getReturn());
    }

    public function testConcurrentWaitsGetTheirOwnEvents(): void
    {
        $trap = new Trap();
        $since = self::cursor($trap);
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
        self::assertListed($trap, ['since' => $since, 'type' => 'var-dump', 'count' => 2], $dumps->getReturn());
    }

    public function testResumesWithoutChangesCheckNothing(): void
    {
        $trap = Trap::scripted();
        $trap->store->add(ScriptedView::event(self::uuid(1), 'needle 1', 1.0));
        $trap->store->add(ScriptedView::event(self::uuid(2), 'needle 2', 2.0));
        $arguments = ['since' => $trap->store->id . ':0', 'search' => 'needle', 'count' => 3];
        $fiber = self::wait($trap, $arguments);

        $checked = $trap->view()->renders;
        self::spin($fiber, 100);
        $idle = $trap->view()->renders;
        $trap->store->add(ScriptedView::event(self::uuid(3), 'needle 3', 3.0));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::same($checked, 2);
        Assert::same($idle, 2);
        Assert::count($result['events'], 3);
        Assert::same($trap->view()->renders, 3, 'The texts rendered before are cached.');
        self::assertListed($trap, $arguments, $result);
    }

    public function testSlowSearchLetsOtherFibersRun(): void
    {
        $trap = Trap::scripted();
        $trap->view()->delay = 25_000;
        $since = self::cursor($trap);
        $fiber = self::wait($trap, ['search' => 'needle', 'count' => 3]);

        for ($i = 1; $i <= 3; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), "needle $i", (float) $i));
        }
        $fiber->resume();
        $suspended = $fiber->isSuspended();
        self::finish($fiber);
        $result = $fiber->getReturn();

        Assert::true($suspended, 'The scan suspends between the slow texts.');
        Assert::count($result['events'], 3);
        self::assertListed($trap, ['since' => $since, 'search' => 'needle', 'count' => 3], $result);
    }

    public function testSlowSearchStaysWithinTheBudget(): void
    {
        $trap = Trap::scripted();
        $trap->container->set(new Scan(0.05, 0.01));
        $trap->view()->delay = 10_000;
        for ($i = 1; $i <= 30; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), "text $i", (float) $i));
        }
        $arguments = ['since' => $trap->store->id . ':0', 'search' => 'needle', 'timeout' => 0];

        $start = \microtime(true);
        $fiber = self::wait($trap, $arguments);
        $resumes = self::finish($fiber);
        $elapsed = \microtime(true) - $start;
        $result = $fiber->getReturn();

        Assert::int($resumes)->greaterThan(0);
        Assert::numeric($elapsed)->lessThan(0.2);
        Assert::same($result['events'], []);
        Assert::true($result['has_more']);
        Assert::true($result['timed_out']);
        Assert::string($result['cursor'])->notSame($trap->store->id . ':' . $trap->store->cursor());
    }

    public function testPassesOutOfTimeGoOnWithoutChanges(): void
    {
        $trap = Trap::scripted();
        // A pass renders three texts or so, and the next one gets further with them cached
        $trap->container->set(new Scan(0.03, 0.01));
        $trap->view()->delay = 10_000;
        for ($i = 1; $i <= 10; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), "text $i", (float) $i));
        }
        $cursor = $trap->store->cursor();
        $arguments = ['since' => $trap->store->id . ':0', 'search' => 'text 10', 'timeout' => 5];

        $fiber = self::wait($trap, $arguments);
        $first = $trap->view()->renders;
        self::finish($fiber);
        $result = $fiber->getReturn();

        Assert::int($first)->lessThan(10);
        Assert::same($trap->store->cursor(), $cursor);
        Assert::same($trap->view()->renders, 10);
        Assert::same(\array_column($result['events'], 'id'), [self::uuid(10)]);
        Assert::false($result['timed_out']);
        Assert::false($result['has_more']);
    }

    public function testPassesWithoutCachedTextsSuspendUntilTheTimeout(): void
    {
        $trap = Trap::scripted();
        $trap->container->set(new Scan(0.0));
        // No text is kept: every pass renders the first record again and gets no further
        $trap->container->set(new TextCache($trap->get(EventViews::class), $trap->store, maxBytes: 0));
        for ($i = 1; $i <= 3; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), "text $i", (float) $i));
        }

        $fiber = self::wait($trap, ['since' => $trap->store->id . ':0', 'search' => 'text 3', 'timeout' => 0.05]);
        $resumes = self::finish($fiber);
        $result = $fiber->getReturn();

        Assert::int($resumes)->greaterThan(1);
        Assert::same($result['events'], []);
        Assert::true($result['has_more']);
        Assert::true($result['timed_out']);
        Assert::same($result['cursor'], $trap->store->id . ':1');
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

    public function testDisconnectedClientGetsNoMoreEventsChecked(): void
    {
        $trap = Trap::scripted();
        $tool = $trap->get(WaitForEvents::class);
        $caller = $trap->get(Caller::class);
        $gone = false;
        $disconnected = static function () use (&$gone): bool {
            return $gone;
        };
        $fiber = new \Fiber(static fn(): array => $caller->connected(
            $disconnected,
            static fn(): array => $tool->call(['search' => 'needle']),
        ));

        $fiber->start();
        $gone = true;
        $trap->store->add(ScriptedView::event(self::uuid(1), 'needle', 1.0));
        $fiber->resume();

        Assert::true($fiber->isTerminated());
        Assert::same($fiber->getReturn()['events'], []);
        Assert::same($trap->view()->renders, 0);
    }

    public function testOutsideFiberChecksOnce(): void
    {
        $trap = new Trap();
        $cursor = $trap->get(ListEvents::class)->call([])['cursor'];
        $trap->add(Trap::dump('there'));

        $result = $trap->get(WaitForEvents::class)->call(['since' => $cursor, 'count' => 2]);

        Assert::same(self::summaries($result), ['there']);
        Assert::true($result['timed_out']);
        self::assertListed($trap, ['since' => $cursor, 'count' => 2], $result);
    }

    public function testOutsideFiberStopsWhereTheScanRanOutOfTime(): void
    {
        $trap = Trap::scripted();
        $trap->container->set(new Scan(0.0));
        $trap->view()->delay = 5_000;
        for ($i = 1; $i <= 3; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), "text $i", (float) $i));
        }

        $result = $trap->get(WaitForEvents::class)->call(['since' => $trap->store->id . ':0', 'search' => 'text 3']);

        Assert::same($result['events'], []);
        Assert::true($result['has_more']);
        Assert::true($result['timed_out']);
        Assert::same($result['cursor'], $trap->store->id . ':1');
    }

    public function testTimeoutOverTheLimitFails(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`timeout` must be a number from 0 to 50.');

        (new Trap())->get(WaitForEvents::class)->call(['timeout' => 51]);
    }

    public function testEventUpdatedWhileWaitingIsListedOnceAtItsLatestText(): void
    {
        $trap = Trap::scripted();
        $since = self::cursor($trap);
        $arguments = ['count' => 3, 'include' => 'text'];
        $fiber = self::wait($trap, $arguments);

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
        self::assertListed($trap, [...$arguments, 'since' => $since], $result);
    }

    public function testUpdateAfterANewEventIsListedAtItsLatestText(): void
    {
        $trap = Trap::scripted();
        $since = self::cursor($trap);
        $arguments = ['count' => 2, 'include' => 'text'];
        $fiber = self::wait($trap, $arguments);

        $trap->store->add(ScriptedView::event(self::uuid(1), 'first', 1.0));
        $fiber->resume();
        $trap->store->add(ScriptedView::event(self::uuid(2), 'second', 2.0));
        $trap->store->add(ScriptedView::event(self::uuid(1), 'first, updated', 1.0));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::same(\array_column($result['events'], 'text'), ['second', 'first, updated']);
        Assert::false($result['timed_out']);
        Assert::false($result['has_more']);
        self::assertListed($trap, [...$arguments, 'since' => $since], $result);
    }

    public function testEventDeletedWhileWaitingIsNotReturned(): void
    {
        $trap = Trap::scripted();
        $since = self::cursor($trap);
        $arguments = ['count' => 2, 'timeout' => 0.05];
        $fiber = self::wait($trap, $arguments);

        $trap->store->add(ScriptedView::event(self::uuid(1), 'deleted', 1.0));
        $fiber->resume();
        $trap->store->delete(self::uuid(1));
        self::finish($fiber);
        $result = $fiber->getReturn();

        Assert::same($result['events'], []);
        Assert::true($result['timed_out']);
        self::assertListed($trap, [...$arguments, 'since' => $since], $result);
    }

    public function testEventDeletedWhileWaitingDoesNotCount(): void
    {
        $trap = Trap::scripted();
        $since = self::cursor($trap);
        $arguments = ['count' => 2];
        $fiber = self::wait($trap, $arguments);

        $trap->store->add(ScriptedView::event(self::uuid(1), 'deleted', 1.0));
        $fiber->resume();
        $trap->store->delete(self::uuid(1));
        $trap->store->add(ScriptedView::event(self::uuid(2), 'second', 2.0));
        $fiber->resume();
        $waiting = $fiber->isSuspended();
        $trap->store->add(ScriptedView::event(self::uuid(3), 'third', 3.0));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::true($waiting, 'The deleted event counts no more.');
        Assert::same(\array_column($result['events'], 'id'), [self::uuid(2), self::uuid(3)]);
        Assert::false($result['timed_out']);
        self::assertListed($trap, [...$arguments, 'since' => $since], $result);
    }

    public function testUpdateThatStopsMatchingIsNotReturnedNorCounted(): void
    {
        $trap = Trap::scripted();
        $since = self::cursor($trap);
        $arguments = ['search' => 'needle', 'count' => 2];
        $fiber = self::wait($trap, $arguments);

        $trap->store->add(ScriptedView::event(self::uuid(1), 'needle 1', 1.0));
        $fiber->resume();
        $trap->store->add(ScriptedView::event(self::uuid(1), 'no longer', 1.0));
        $trap->store->add(ScriptedView::event(self::uuid(2), 'needle 2', 2.0));
        $fiber->resume();
        $waiting = $fiber->isSuspended();
        $trap->store->add(ScriptedView::event(self::uuid(3), 'needle 3', 3.0));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::true($waiting, 'The event that stopped matching counts no more.');
        Assert::same(\array_column($result['events'], 'id'), [self::uuid(2), self::uuid(3)]);
        Assert::false($result['timed_out']);
        self::assertListed($trap, [...$arguments, 'since' => $since], $result);
    }

    public function testTextsFillingTheResultEndTheWait(): void
    {
        $trap = Trap::scripted();
        $perEvent = ListEvents::MAX_TEXT_LENGTH;
        $fitting = \intdiv(ListEvents::MAX_TOTAL_TEXT_LENGTH, $perEvent);
        for ($i = 1; $i <= $fitting; ++$i) {
            $last = $trap->store->add(ScriptedView::event(self::uuid($i), \str_repeat('x', $perEvent), (float) $i));
        }
        $arguments = [
            'since' => $trap->store->id . ':0',
            'count' => $fitting + 2,
            'include' => 'text',
            'max_length' => $perEvent,
        ];
        $fiber = self::wait($trap, $arguments);

        $waiting = $fiber->isSuspended();
        $trap->store->add(ScriptedView::event(self::uuid(99), 'over the cap', 99.0));
        $fiber->resume();
        $result = $fiber->getReturn();

        Assert::true($waiting, 'The texts fit.');
        Assert::count($result['events'], $fitting);
        Assert::true($result['has_more']);
        Assert::false($result['timed_out']);
        Assert::same($result['cursor'], $trap->store->id . ':' . $last->seq);
        self::assertListed($trap, $arguments, $result);
    }

    public function testUpdateGrowingPastTheTextsIsLeftToTheNextCall(): void
    {
        $trap = Trap::scripted();
        $perEvent = ListEvents::MAX_TEXT_LENGTH;
        for ($i = 1; $i <= 9; ++$i) {
            $trap->store->add(ScriptedView::event(self::uuid($i), \str_repeat('x', $perEvent), (float) $i));
        }
        $trap->store->add(ScriptedView::event(self::uuid(10), \str_repeat('x', 500), 10.0));
        $trap->store->add(ScriptedView::event(self::uuid(11), \str_repeat('x', $perEvent - 1_000), 11.0));
        $arguments = [
            'since' => $trap->store->id . ':0',
            'count' => 13,
            'include' => 'text',
            'max_length' => $perEvent,
        ];
        $fiber = self::wait($trap, $arguments);

        $waiting = $fiber->isSuspended();
        $trap->store->add(ScriptedView::event(self::uuid(11), \str_repeat('y', $perEvent), 11.0));
        $fiber->resume();
        $result = $fiber->getReturn();
        $next = $trap->get(ListEvents::class)->call([...$arguments, 'since' => $result['cursor']]);

        Assert::true($waiting, 'The texts of 499 500 characters fit.');
        Assert::count($result['events'], 10);
        Assert::array(\array_column($result['events'], 'id'))->notContains(self::uuid(11));
        Assert::true($result['has_more']);
        Assert::false($result['timed_out']);
        Assert::same(\array_column($next['events'], 'id'), [self::uuid(11)]);
        Assert::same($next['events'][0]['text'], \str_repeat('y', $perEvent));
        self::assertListed($trap, $arguments, $result);
    }

    /**
     * The result is that of `list_events` with the same arguments and `limit: count`.
     *
     * @param array<non-empty-string, mixed> $arguments Of the wait, with `since`.
     */
    private static function assertListed(Trap $trap, array $arguments, array $result): void
    {
        $count = $arguments['count'] ?? WaitForEvents::DEFAULT_COUNT;
        unset($arguments['count'], $arguments['timeout']);

        $listed = $trap->get(ListEvents::class)->call([...$arguments, 'limit' => $count]);

        Assert::same(\array_diff_key($result, ['timed_out' => true]), $listed);
    }

    /**
     * The cursor a wait without `since` starts from.
     */
    private static function cursor(Trap $trap): string
    {
        return $trap->store->id . ':' . $trap->store->cursor();
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
