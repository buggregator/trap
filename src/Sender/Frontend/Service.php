<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend;

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
        $this->debug('Delete event %s', $uuid);
        $this->eventsStorage->delete($uuid);
        return true;
    }

    #[StaticRoute(Method::Delete, 'api/events')]
    public function eventsDelete(): bool
    {
        $this->debug('Delete all events');
        $this->eventsStorage->clear();
        return true;
    }

    private function debug(string $pattern, string ...$args): void
    {
        $this->logger->debug("[UI Service] $pattern", ...$args);
    }
}
