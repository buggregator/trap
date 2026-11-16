<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Push;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Event\StoreListener;
use Buggregator\Trap\Server\Traffic\Websocket\Frame;
use Buggregator\Trap\Support\Json;

/**
 * Pushes the new and changed events of the store to the connected UI clients.
 *
 * Deletions are not pushed: the UI handles only the `event.received` push. It drops an event from its list
 * by itself when it asks to delete it, and shows the others it has until a reload.
 *
 * @internal
 */
final class EventPusher implements StoreListener
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    #[\Override]
    public function added(Record $record): void
    {
        $this->push($record);
    }

    /**
     * The UI replaces the event of the same UUID.
     */
    #[\Override]
    public function updated(Record $record): void
    {
        $this->push($record);
    }

    #[\Override]
    public function deleted(Record $record): void {}

    #[\Override]
    public function cleared(): void {}

    private function push(Record $record): void
    {
        $this->connectionPool->send(Frame::text(Json::encode(
            new Push(
                event: 'event.received',
                channel: 'events',
                data: $record->event,
            ),
        )));
    }
}
