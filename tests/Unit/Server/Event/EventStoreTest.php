<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Event;

use Buggregator\Trap\Server\Config\Events as Config;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Event\StoreListener;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Proto\Frame\Binary;
use Buggregator\Trap\Support\Uuid;
use Buggregator\Trap\Tests\Unit\Server\Event\Stub\RecordingListener;
use Nyholm\Psr7\Stream;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(EventStore::class)]
final class EventStoreTest
{
    public function testMaxEventsLimit(): void
    {
        $storage = self::store(maxEvents: 2);

        $storage->add($e1 = $this->createEvent());
        $storage->add($e2 = $this->createEvent());
        $storage->add($e3 = $this->createEvent());

        Assert::count($storage, 2);
        // In an ordered event sequence of adding events, the first event should be removed
        Assert::null($storage->get($e1->uuid));
        Assert::notNull($storage->get($e2->uuid));
        Assert::notNull($storage->get($e3->uuid));
    }

    public function testMaxEventsLimitWithSort(): void
    {
        $storage = self::store(maxEvents: 2);

        $storage->add($e1 = $this->createEvent());
        $storage->add($e2 = $this->createEvent());
        $storage->add($e3 = $this->createEvent(timestamp: \microtime(true) - 1));

        Assert::count($storage, 2);
        // new event should be added in order of timestamp, and then the first event should be removed
        Assert::null($storage->get($e3->uuid));
        Assert::notNull($storage->get($e1->uuid));
        Assert::notNull($storage->get($e2->uuid));
        // Check order of events
        $events = \iterator_to_array($storage->getIterator(), false);
        Assert::same($events[0]->uuid, $e2->uuid);
        Assert::same($events[1]->uuid, $e1->uuid);
    }

    public function testEveryChangeTakesTheNextSeq(): void
    {
        $store = self::store();

        $a = $store->add($this->createEvent('a'));
        $b = $store->add($this->createEvent('b'));
        $updated = $store->add($this->createEvent('a'));

        Assert::same([$a->seq, $b->seq, $updated->seq], [1, 2, 3]);
        Assert::same($store->cursor(), 3);
        Assert::same($store->record('a'), $updated);
    }

    public function testCursorIsZeroWhenNothingChanged(): void
    {
        Assert::same(self::store()->cursor(), 0);
    }

    public function testUpdateAddsFramesToTheStoredOnes(): void
    {
        $store = self::store();
        $store->keepFrames();
        [$first, $second] = [self::frame(), self::frame()];

        $store->add($this->createEvent('a'), $first);
        $record = $store->add($this->createEvent('a', payload: ['merged' => true]), $second);

        Assert::same($record->frames, [$first, $second]);
        Assert::same($store->get('a')?->payload, ['merged' => true]);
        Assert::count($store, 1);
    }

    public function testChangedSinceListsChangesAfterTheCursorInOrder(): void
    {
        $store = self::store();
        $store->add($this->createEvent('a'));
        $store->add($this->createEvent('b'));
        $cursor = $store->cursor();
        $store->add($this->createEvent('c'));
        $store->add($this->createEvent('a'));

        Assert::same(self::uuids($store->changedSince($cursor)), ['c', 'a']);
        Assert::same(self::uuids($store->changedSince(0)), ['b', 'c', 'a']);
        Assert::same($store->changedSince($store->cursor()), []);
    }

    public function testChangedSinceSkipsDeletedEvents(): void
    {
        $store = self::store();
        $store->add($this->createEvent('a'));
        $store->add($this->createEvent('b'));

        $store->delete('a');

        Assert::same(self::uuids($store->changedSince(0)), ['b']);
    }

    public function testListenersGetEveryChange(): void
    {
        $store = self::store();
        $store->listen($listener = new RecordingListener());

        $store->add($this->createEvent('a'));
        $store->add($this->createEvent('a'));
        $store->delete('a');
        $store->delete('missing');
        $store->clear();

        Assert::same($listener->calls, ['added:a', 'updated:a', 'deleted:a', 'cleared']);
    }

    public function testListenersGetTheEventPushedOutByLimit(): void
    {
        $store = self::store(maxEvents: 1);
        $store->listen($listener = new RecordingListener());

        $store->add($this->createEvent('a', timestamp: 1.0));
        $store->add($this->createEvent('b', timestamp: 2.0));

        // The store is within the limit by the time the listeners learn of the new event
        Assert::same($listener->calls, ['added:a', 'deleted:a', 'added:b']);
    }

    public function testEventOlderThanAllKeptOnesIsNotKeptAtTheLimit(): void
    {
        $store = self::store(maxEvents: 1);
        $store->listen($listener = new RecordingListener());

        $store->add($this->createEvent('new', timestamp: 2.0));
        $store->add($this->createEvent('old', timestamp: 1.0));

        Assert::same(self::uuids($store->changedSince(0)), ['new']);
        Assert::same($listener->calls, ['added:new']);
    }

    public function testFailingListenerIsLoggedAndTheOthersGetTheChange(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $store = self::store(logger: new Logger($output));
        $store->listen(new class implements StoreListener {
            public function added(Record $record): void
            {
                throw new \RuntimeException('Listener broke.');
            }

            public function updated(Record $record): void {}

            public function deleted(Record $record): void {}

            public function cleared(): void {}
        });
        $store->listen($listener = new RecordingListener());

        $record = $store->add($this->createEvent('a'));

        Assert::same($store->record('a'), $record);
        Assert::same($listener->calls, ['added:a']);
        Assert::string($output->fetch())->contains('Event store listener ')->contains('Listener broke.');
    }

    public function testFramesAreDroppedUnlessKept(): void
    {
        $store = self::store();

        $record = $store->add($this->createEvent('a'), self::frame());

        Assert::same($record->frames, []);
    }

    public function testEveryStoreHasItsOwnId(): void
    {
        $id = self::store()->id;

        Assert::string($id)->matchesRegex('/^[0-9a-f]{8}$/');
        Assert::notSame(self::store()->id, $id);
    }

    /**
     * @param int<1, max> $maxEvents
     */
    private static function store(int $maxEvents = 200, Logger $logger = new Logger()): EventStore
    {
        $config = new Config();
        $config->maxEvents = $maxEvents;

        return new EventStore($config, $logger);
    }

    private static function frame(): Binary
    {
        return new Binary(Stream::create('data'));
    }

    /**
     * @param list<Record> $records
     * @return list<non-empty-string>
     */
    private static function uuids(array $records): array
    {
        return \array_map(static fn(Record $record): string => $record->event->uuid, $records);
    }

    /**
     * @param non-empty-string|null $uuid
     */
    private function createEvent(
        ?string $uuid = null,
        ?string $type = null,
        ?array $payload = null,
        ?float $timestamp = null,
    ): Event {
        return new Event(
            uuid: $uuid ?? Uuid::uuid4(),
            type: $type ?? 'var-dump',
            payload: $payload ?? [],
            timestamp: $timestamp ?? \microtime(true),
        );
    }
}
