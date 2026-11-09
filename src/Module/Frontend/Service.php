<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Application\Module\Modules;
use Buggregator\Trap\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Frontend\Message\EventCollection;
use Buggregator\Trap\Module\Frontend\Message\Projects;
use Buggregator\Trap\Module\Frontend\Message\Settings;
use Buggregator\Trap\Module\Frontend\Message\Success;
use Buggregator\Trap\Module\Frontend\Message\Version;

/**
 * @internal
 */
final class Service
{
    public function __construct(
        private readonly Logger $logger,
        private readonly EventStorage $eventsStorage,
        private readonly Modules $modules,
    ) {}

    #[StaticRoute(Method::Get, 'api/version')]
    public function version(): Version
    {
        $this->debug('Get version');
        return new Version();
    }

    #[RegexpRoute(Method::Delete, '#^api/event/(?<uuid>[a-f0-9-]++)$#i')]
    #[AssertSuccess(Method::Delete, 'api/event/0145a0e0-0b1a-4e4a-9b1a', ['uuid' => '0145a0e0-0b1a-4e4a-9b1a']),
        AssertFail(Method::Delete, 'api/event/foo-bar-baz'),
        AssertFail(Method::Delete, 'api/event/'),]
    public function eventDelete(string $uuid): Success
    {
        $this->debug('Delete event %s', $uuid);
        $this->eventsStorage->delete($uuid);
        return new Success();
    }

    #[RegexpRoute(Method::Get, '#^api/event/(?<uuid>[a-f0-9-]++)$#i')]
    #[AssertSuccess(Method::Get, 'api/event/0145a0e0-0b1a-4e4a-9b1a', ['uuid' => '0145a0e0-0b1a-4e4a-9b1a']),
        AssertFail(Method::Get, 'api/event/foo-bar-baz'),
        AssertFail(Method::Get, 'api/event/'),]
    public function eventShow(string $uuid): Event|Success
    {
        $this->debug('Show event %s', $uuid);
        $event = $this->eventsStorage->get($uuid);
        return $event ?? new Success(status: false);
    }

    /**
     * @param array $uuids Events to delete; when empty, the events of the type and the project are deleted.
     * @param string|null $type Event type; any when null.
     * @param string|null $project Project key; any when null.
     */
    #[StaticRoute(Method::Delete, 'api/events')]
    #[StaticRoute(Method::Delete, 'api/events/preview')]
    #[AssertSuccess(Method::Delete, '/api/events'),
        AssertSuccess(Method::Delete, '/api/events/preview'),
        AssertFail(Method::Delete, 'api/events/'),
        AssertFail(Method::Delete, 'api/event'),]
    public function eventsDelete(array $uuids = [], ?string $type = null, ?string $project = null): Success
    {
        if ($uuids !== []) {
            $this->debug('Delete %s events', (string) \count($uuids));
            try {
                foreach ($uuids as $uuid) {
                    \is_string($uuid) or throw new \InvalidArgumentException('UUID must be a string');
                    $this->eventsStorage->delete($uuid);
                }
            } catch (\Throwable $e) {
                $this->logger->exception($e);
                return new Success(status: false);
            }

            return new Success();
        }

        if ($type === null && $project === null) {
            $this->debug('Delete all events');
            $this->eventsStorage->clear();
            return new Success();
        }

        $this->debug('Delete events of type %s in project %s', $type ?? 'any', $project ?? 'any');
        foreach (\iterator_to_array($this->eventsStorage) as $event) {
            ($type === null || $event->type === $type) && ($project === null || $event->projectKey() === $project)
                and $this->eventsStorage->delete($event->uuid);
        }

        return new Success();
    }

    /**
     * @param string|null $project Project key; all the events when null.
     */
    #[StaticRoute(Method::Get, 'api/events')]
    #[StaticRoute(Method::Get, 'api/events/preview')]
    #[AssertSuccess(Method::Get, '/api/events'),
        AssertSuccess(Method::Get, '/api/events/preview?project=default'),
        AssertFail(Method::Get, 'api/event'),
        AssertFail(Method::Post, 'api/events'),]
    public function eventsList(?string $project = null): EventCollection
    {
        $this->debug('List events of project %s', $project ?? 'any');
        $events = [];
        foreach ($this->eventsStorage as $event) {
            ($project === null || $event->projectKey() === $project) and $events[] = $event;
        }

        return new EventCollection(events: $events);
    }

    #[StaticRoute(Method::Get, 'api/projects')]
    #[AssertSuccess(Method::Get, '/api/projects'),
        AssertFail(Method::Get, 'api/project'),]
    public function projects(): Projects
    {
        $keys = [];
        foreach ($this->eventsStorage as $event) {
            $keys[$event->projectKey()] = true;
        }

        return new Projects(\array_keys($keys));
    }

    #[StaticRoute(Method::Get, 'api/settings')]
    #[AssertFail(Method::Get, 'api/setting'),
        AssertFail(Method::Post, 'api/settings'),
        AssertSuccess(Method::Get, '/api/settings'),]
    public function settings(): Settings
    {
        $this->debug('List settings');
        $events = [];
        foreach ($this->modules->implementing(ProvidesUi::class) as $module) {
            $events = [...$events, ...$module->uiEventTypes()];
        }

        return new Settings([...$events, Mapper\HttpRequest::TYPE]);
    }

    private function debug(string $pattern, string ...$args): void
    {
        $this->logger->debug("[UI Service] $pattern", ...$args);
    }
}
