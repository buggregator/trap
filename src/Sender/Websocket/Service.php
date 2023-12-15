<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket;

use Buggregator\Trap\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Logger;

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

    #[RegexpRoute(Method::Delete, '#^/api/events/(?<uuid>[a-f0-9-]++)#i')]
    public function eventDelete(string $uuid): bool
    {
        $this->eventsStorage->delete($uuid);
        return true;
    }

    #[StaticRoute(Method::Delete, 'api/events')]
    public function eventsDelete(): bool
    {
        $this->eventsStorage->clear();
        return true;
    }
}
