<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Message\UiEvent;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Event\StoreListener;

/**
 * Pushes the changes of the store to the connected UI clients, in the channel of the event project.
 *
 * @internal
 */
final class EventPusher implements StoreListener
{
    public function __construct(
        private readonly Publisher $publisher,
        private readonly EventStore $events,
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

    /**
     * The UI drops the event from its list; the oldest event pushed out by a new one, too.
     */
    #[\Override]
    public function deleted(Record $record): void
    {
        $project = $record->event->projectKey();
        $this->publisher->toProject($project, 'event.deleted', [
            'uuid' => $record->event->uuid,
            'project' => $project,
        ]);
    }

    /**
     * The UI loads its list again.
     */
    #[\Override]
    public function cleared(): void
    {
        $this->publisher->toAllProjects('events.cleared');
    }

    private function push(Record $record): void
    {
        $this->publisher->toProject(
            $record->event->projectKey(),
            'event.received',
            UiEvent::of($record->event, $this->events, preview: true),
        );
    }
}
