<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Success;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Server\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Logger;

/**
 * The UI clears Sentry data along with every clear of the events, and takes the clear for failed
 * when this call fails.
 *
 * @internal
 */
final class ClearController
{
    public function __construct(
        private readonly Logger $logger,
        private readonly EventStore $events,
    ) {}

    /**
     * Deletes the Sentry events of the project.
     *
     * @param string|null $project Project key; any when null.
     * @param mixed $keep_pinned Whether the pinned events stay; the UI sends `1` in the query.
     */
    #[StaticRoute(Method::Delete, 'api/sentry/all')]
    #[AssertSuccess(Method::Delete, 'api/sentry/all?project=default&keep_pinned=1'),
        AssertFail(Method::Get, 'api/sentry/all'),]
    public function clear(?string $project = null, mixed $keep_pinned = false): Success
    {
        $keepPinned = \filter_var($keep_pinned, \FILTER_VALIDATE_BOOL);
        $project === '' and $project = null;
        $this->logger->debug('[UI Service] Delete Sentry events of project %s', $project ?? 'any');

        foreach (\iterator_to_array($this->events) as $event) {
            $event->type === 'sentry'
            && ($project === null || $event->projectKey() === $project)
            && !($keepPinned && $this->events->isPinned($event->uuid))
                and $this->events->delete($event->uuid);
        }

        return new Success();
    }
}
