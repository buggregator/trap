<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Ray\Frontend;

use Buggregator\Trap\Module\Mcp\View\PayloadSummary;
use Buggregator\Trap\Module\Ray\Frontend\LockEvents;
use Buggregator\Trap\Module\Ray\Frontend\Payload;
use Buggregator\Trap\Module\Ray\Lock\LockState;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Event\StoreListener;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(LockEvents::class)]
#[Test]
final class LockEventsTest
{
    public function testReleaseWritesStateIntoTheLockPayloadsOfTheName(): void
    {
        $store = new EventStore();
        $store->add(self::event('e1', 'aaa', project: 'shop'));
        $store->add(self::event('e2', 'bbb'));
        $store->add(new Event('e3', 'smtp', ['name' => 'aaa'], 3.0));

        $updated = (new LockEvents($store))->release('aaa', LockState::Stopped);

        Assert::same($updated, 1);
        $event = $store->get('e1');
        \assert($event?->payload instanceof Payload);
        Assert::same($event->payload->payloads[0], ['type' => 'log', 'content' => ['values' => ['x']]]);
        Assert::same($event->payload->payloads[1]['content'], ['name' => 'aaa', 'state' => 'stopped']);
        Assert::same($event->project, 'shop');
        Assert::same($event->timestamp, 1.0);
        Assert::same(self::lockContent($store, 'e2'), ['name' => 'bbb']);
        Assert::same($store->get('e3')?->payload, ['name' => 'aaa']);
    }

    /**
     * spatie/ray names a lock by the second of the pause: clients pausing in the same second share it.
     */
    public function testReleaseUpdatesEveryEventOfASharedLock(): void
    {
        $store = new EventStore();
        $store->add(self::event('e1', 'aaa'));
        $store->add(self::event('e2', 'aaa'));

        Assert::same((new LockEvents($store))->release('aaa', LockState::Continued), 2);
        Assert::same(self::lockContent($store, 'e1')['state'], 'continued');
        Assert::same(self::lockContent($store, 'e2')['state'], 'continued');
    }

    /**
     * A second click of another tab must not push the event again.
     */
    public function testReleaseOfTheSameStateChangesNothing(): void
    {
        $store = new EventStore();
        $store->add(self::event('e1', 'aaa'));
        $updates = new class implements StoreListener {
            public int $count = 0;

            public function added(Record $record): void {}

            public function updated(Record $record): void
            {
                ++$this->count;
            }

            public function deleted(Record $record): void {}

            public function cleared(): void {}
        };
        $store->listen($updates);
        $events = new LockEvents($store);

        $events->release('aaa', LockState::Continued);
        Assert::same($events->release('aaa', LockState::Continued), 0);
        Assert::same($updates->count, 1);
    }

    public function testStateOf(): void
    {
        $store = new EventStore();
        $store->add(self::event('e1', 'aaa'));
        $events = new LockEvents($store);

        Assert::null($events->stateOf('aaa'));
        Assert::null($events->stateOf('zzz'));

        $events->release('aaa', LockState::Stopped);
        Assert::same($events->stateOf('aaa'), LockState::Stopped);
    }

    /**
     * AI agents read the state from the summary `list_events` and `get_event` show.
     */
    public function testMcpSummaryShowsTheState(): void
    {
        $store = new EventStore();
        $store->add(self::event('e1', 'aaa'));
        (new LockEvents($store))->release('aaa', LockState::Continued);

        $event = $store->get('e1');
        \assert($event !== null);
        Assert::string((string) (new PayloadSummary())->summary($event))
            ->contains('create_lock: {"name":"aaa","state":"continued"}');
    }

    /**
     * @param non-empty-string $uuid
     */
    private static function event(string $uuid, string $lock, ?string $project = null): Event
    {
        return new Event($uuid, 'ray', new Payload($uuid, [
            ['type' => 'log', 'content' => ['values' => ['x']]],
            ['type' => 'create_lock', 'content' => ['name' => $lock]],
        ], []), 1.0, project: $project);
    }

    private static function lockContent(EventStore $store, string $uuid): array
    {
        $payload = $store->get($uuid)?->payload;
        \assert($payload instanceof Payload);

        return $payload->payloads[1]['content'];
    }
}
