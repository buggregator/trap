<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend;

use Buggregator\Trap\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Sender\Frontend\Message\Event;
use Buggregator\Trap\Sender\Frontend\Message\EventCollection;
use Buggregator\Trap\Sender\Frontend\Message\Success;
use Buggregator\Trap\Sender\Frontend\Message\Version;

/**
 * @internal
 */
final class Service
{
    public function __construct(
        private readonly Logger $logger,
        private readonly EventsStorage $eventsStorage,
    ) {
    }

    #[StaticRoute(Method::Get, 'api/version')]
    public function version(): Version
    {
        $this->debug('Get version');
        return new Version();
    }

    #[RegexpRoute(Method::Delete, '#^/api/event/(?<uuid>[a-f0-9-]++)#i')]
    public function eventDelete(string $uuid): Success
    {
        $this->debug('Delete event %s', $uuid);
        $this->eventsStorage->delete($uuid);
        return new Success();
    }

    #[RegexpRoute(Method::Get, '#^/api/event/(?<uuid>[a-f0-9-]++)#i')]
    public function eventShow(string $uuid): Event|Success
    {
        $this->debug('Show event %s', $uuid);
        $event = $this->eventsStorage->get($uuid);
        // todo: verify correct format
        return $event ?? new Success(status: false);
    }

    #[StaticRoute(Method::Delete, 'api/events')]
    public function eventsDelete(): Success
    {
        $this->debug('Delete all events');
        $this->eventsStorage->clear();
        return new Success();
    }

    #[StaticRoute(Method::Get, 'api/events')]
    public function eventsList(): EventCollection
    {
        $this->debug('List all events');
        return new EventCollection(events: \iterator_to_array($this->eventsStorage, false));
    }

    private function debug(string $pattern, string ...$args): void
    {
        $this->logger->debug("[UI Service] $pattern", ...$args);
    }
}
