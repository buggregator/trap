<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Config\Server\Frontend\EventStorage as Config;
use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventStorage;
use Buggregator\Trap\Support\Uuid;
use PHPUnit\Framework\TestCase;

class EventsStorageTest extends TestCase
{
    public function testMaxEventsLimit(): void
    {
        $config = new Config();
        $config->maxEvents = 2;
        $storage = new EventStorage($config);

        $storage->add($e1 = $this->createEvent());
        $storage->add($e2 = $this->createEvent());
        $storage->add($e3 = $this->createEvent());

        self::assertCount(2, $storage);
        // In an ordered event sequence of adding events, the first event should be removed
        self::assertNull($storage->get($e1->uuid));
        self::assertNotNull($storage->get($e2->uuid));
        self::assertNotNull($storage->get($e3->uuid));
    }

    public function testMaxEventsLimitWithSort(): void
    {
        $config = new Config();
        $config->maxEvents = 2;
        $storage = new EventStorage($config);

        $storage->add($e1 = $this->createEvent());
        $storage->add($e2 = $this->createEvent());
        $storage->add($e3 = $this->createEvent(timestamp: \microtime(true) - 1));

        self::assertCount(2, $storage);
        // new event should be added in order of timestamp, and then the first event should be removed
        self::assertNull($storage->get($e3->uuid));
        self::assertNotNull($storage->get($e1->uuid));
        self::assertNotNull($storage->get($e2->uuid));
        // Check order of events
        $events = \iterator_to_array($storage->getIterator(), false);
        self::assertSame($e2->uuid, $events[0]->uuid);
        self::assertSame($e1->uuid, $events[1]->uuid);
    }

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
