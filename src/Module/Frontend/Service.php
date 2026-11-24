<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Config\Frontend as FrontendConfig;
use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Frontend\Message\EventCollection;
use Buggregator\Trap\Module\Frontend\Message\Projects;
use Buggregator\Trap\Module\Frontend\Message\Settings;
use Buggregator\Trap\Module\Frontend\Message\Success;
use Buggregator\Trap\Module\Frontend\Message\UiEvent;
use Buggregator\Trap\Module\Frontend\Message\Version;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Config\TcpPorts;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Internal\Container\Container;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
final class Service
{
    /** @var list<non-empty-string> UI event types the modules produce */
    private readonly array $eventTypes;

    /** @var list<class-string<UiPorts>> */
    private readonly array $uiPorts;

    public function __construct(
        private readonly Logger $logger,
        private readonly EventStore $eventsStorage,
        Modules $modules,
        private readonly FrontendConfig $config,
        private readonly TcpPorts $tcpPorts,
        private readonly Container $container,
        private readonly TelemetryStore $telemetry = new TelemetryStore(),
    ) {
        /** @var array<non-empty-string, class-string> $owners Who declared each event type */
        $owners = [];
        foreach ($modules->implementing(ProvidesEvents::class) as $module) {
            foreach ($module->eventTypes() as $type) {
                isset($owners[$type]) and throw new \LogicException(
                    \sprintf('Event type `%s` of %s is already declared by %s.', $type, $module::class, $owners[$type]),
                );
                $owners[$type] = $module::class;
            }
        }

        $this->eventTypes = \array_keys($owners);
        $this->uiPorts = \array_map(
            static fn(ProvidesUiPorts $module): string => $module->uiPorts(),
            $modules->implementing(ProvidesUiPorts::class),
        );
    }

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
        $event = $this->event($uuid);
        $event === null or $this->eventsStorage->delete($event->uuid);
        return new Success();
    }

    #[RegexpRoute(Method::Get, '#^api/event/(?<uuid>[a-f0-9-]++)$#i')]
    #[AssertSuccess(Method::Get, 'api/event/0145a0e0-0b1a-4e4a-9b1a', ['uuid' => '0145a0e0-0b1a-4e4a-9b1a']),
        AssertFail(Method::Get, 'api/event/foo-bar-baz'),
        AssertFail(Method::Get, 'api/event/'),]
    public function eventShow(string $uuid): UiEvent|Error
    {
        $this->debug('Show event %s', $uuid);
        $event = $this->event($uuid);
        return $event === null ? Error::notFound('Event not found') : UiEvent::of($event, $this->eventsStorage);
    }

    /**
     * A pinned event stays on a clear that keeps the pinned ones, and the events limit pushes it out last.
     */
    #[RegexpRoute(Method::Post, '#^api/event/(?<uuid>[a-f0-9-]++)/pin$#i')]
    #[AssertSuccess(Method::Post, 'api/event/0145a0e0-0b1a-4e4a-9b1a/pin', ['uuid' => '0145a0e0-0b1a-4e4a-9b1a']),
        AssertFail(Method::Post, 'api/event/0145a0e0-0b1a-4e4a-9b1a'),]
    public function eventPin(string $uuid): Success|Error
    {
        $this->debug('Pin event %s', $uuid);
        $event = $this->event($uuid);
        return $event !== null && $this->eventsStorage->pin($event->uuid)
            ? new Success()
            : Error::notFound('Event not found');
    }

    #[RegexpRoute(Method::Delete, '#^api/event/(?<uuid>[a-f0-9-]++)/pin$#i')]
    #[AssertSuccess(Method::Delete, 'api/event/0145a0e0-0b1a-4e4a-9b1a/pin', ['uuid' => '0145a0e0-0b1a-4e4a-9b1a'])]
    public function eventUnpin(string $uuid): Success|Error
    {
        $this->debug('Unpin event %s', $uuid);
        $event = $this->event($uuid);
        return $event !== null && $this->eventsStorage->pin($event->uuid, false)
            ? new Success()
            : Error::notFound('Event not found');
    }

    /**
     * @param array $uuids Events to delete; when empty, the events of the type and the project are deleted.
     * @param string|null $type Event type; any when null.
     * @param string|null $project Project key; any when null.
     * @param mixed $keep_pinned Whether the pinned events stay; the UI sends a JSON boolean.
     */
    #[StaticRoute(Method::Delete, 'api/events')]
    #[StaticRoute(Method::Delete, 'api/events/preview')]
    #[AssertSuccess(Method::Delete, '/api/events'),
        AssertSuccess(Method::Delete, '/api/events/preview'),
        AssertFail(Method::Delete, 'api/events/'),
        AssertFail(Method::Delete, 'api/event'),]
    public function eventsDelete(
        array $uuids = [],
        ?string $type = null,
        ?string $project = null,
        mixed $keep_pinned = false,
    ): Success {
        if ($uuids !== []) {
            $this->debug('Delete %s events', (string) \count($uuids));
            try {
                foreach ($uuids as $uuid) {
                    \is_string($uuid) or throw new \InvalidArgumentException('UUID must be a string');
                    $event = $this->event($uuid);
                    $event === null or $this->eventsStorage->delete($event->uuid);
                }
            } catch (\Throwable $e) {
                $this->logger->exception($e);
                return new Success(code: 400, status: false);
            }

            return new Success();
        }

        $keepPinned = \filter_var($keep_pinned, \FILTER_VALIDATE_BOOL);
        $type === '' and $type = null;
        $project === '' and $project = null;

        if ($type === null && $project === null && !$keepPinned) {
            $this->debug('Delete all events');
            $this->eventsStorage->clear();
            return new Success();
        }

        $this->debug('Delete events of type %s in project %s', $type ?? 'any', $project ?? 'any');
        foreach (\iterator_to_array($this->eventsStorage) as $event) {
            ($type === null || $event->type === $type)
            && ($project === null || $event->projectKey() === $project)
            && !($keepPinned && $this->eventsStorage->isPinned($event->uuid))
                and $this->eventsStorage->delete($event->uuid);
        }

        return new Success();
    }

    /**
     * The HTTP request gives the whole {@see EventQuery}; the WebSocket RPC, which has none, the arguments.
     *
     * @param string|null $project Project key; all the events when null.
     * @param string|null $window Period of the events till now, as `1h` or `7d`; all the events when null or `all`.
     * @param int|string $limit The number of the newest events; all when 0. A query gives a string.
     */
    #[StaticRoute(Method::Get, 'api/events')]
    #[StaticRoute(Method::Get, 'api/events/preview')]
    #[AssertSuccess(Method::Get, '/api/events'),
        AssertSuccess(Method::Get, '/api/events/preview?project=default'),
        AssertFail(Method::Get, 'api/event'),
        AssertFail(Method::Post, 'api/events'),]
    public function eventsList(
        ?string $project = null,
        ?string $window = null,
        int|string $limit = 0,
        ?ServerRequestInterface $__request = null,
    ): EventCollection {
        $query = $this->query($__request, $project, $window, $limit);
        $this->debug('List events of project %s', $query->project ?? 'any');

        return new EventCollection(events: \array_map(
            fn(Event $event): UiEvent => UiEvent::of($event, $this->eventsStorage),
            $query->select($this->eventsStorage),
        ));
    }

    /**
     * Values of the filter keys with their event counts, for the feed filters and the Logs page.
     */
    #[StaticRoute(Method::Get, 'api/events/facets')]
    #[AssertSuccess(Method::Get, '/api/events/facets?type=monolog'),
        AssertFail(Method::Post, 'api/events/facets'),]
    public function eventsFacets(?ServerRequestInterface $__request = null): array
    {
        $counts = $this->query($__request)->facetCounts($this->eventsStorage);

        return ['data' => (object) $counts, 'keys' => \array_keys($counts)];
    }

    /**
     * Events per time bucket by level, for the chart of the Logs page.
     *
     * @param int|string $bucket Bucket width in seconds.
     */
    #[StaticRoute(Method::Get, 'api/events/histogram')]
    #[AssertSuccess(Method::Get, '/api/events/histogram?type=monolog&bucket=60'),
        AssertFail(Method::Post, 'api/events/histogram'),]
    public function eventsHistogram(int|string $bucket = 60, ?ServerRequestInterface $__request = null): array|Error
    {
        $bucket = (int) $bucket;
        if ($bucket < 1 || $bucket > EventQuery::MAX_BUCKET) {
            return new Error(\sprintf('bucket: seconds, a positive integer up to %d (366 days)', EventQuery::MAX_BUCKET), 400);
        }

        return $this->query($__request)->histogram($this->eventsStorage, $bucket);
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

        // A ping URL names a project that may have no event
        foreach ($this->telemetry->crons->monitors() as $monitor) {
            $keys[$monitor->project] = true;
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

        return new Settings($this->eventTypes, $this->ports());
    }

    /**
     * An event by its UUID, else a Sentry error by its Sentry `event_id`: the Feedback tab links an error
     * by it, as buggregator/server names its events, and the page of the error deletes and pins it by it.
     */
    private function event(string $id): ?Event
    {
        return $this->eventsStorage->get($id) ?? $this->sentryEvent($id);
    }

    private function sentryEvent(string $eventId): ?Event
    {
        $eventId = \strtolower(\str_replace('-', '', $eventId));
        if ($eventId === '') {
            return null;
        }

        foreach ($this->eventsStorage as $event) {
            /** @var mixed $id */
            $id = $event->type === 'sentry' && \is_array($event->payload) ? $event->payload['event_id'] ?? null : null;
            if (\is_string($id) && \strtolower(\str_replace('-', '', $id)) === $eventId) {
                return $event;
            }
        }

        return null;
    }

    private function query(
        ?ServerRequestInterface $request,
        ?string $project = null,
        ?string $window = null,
        int|string $limit = 0,
    ): EventQuery {
        return $request === null
            ? new EventQuery(
                project: $project === '' ? null : $project,
                since: EventQuery::since($window),
                limit: \max(0, (int) $limit),
            )
            : EventQuery::fromQueryString($request->getUri()->getQuery());
    }

    /**
     * Ports for the setup guide of the UI. Every TCP port of Trap takes any protocol: a usual port is shown
     * when Trap listens on it, the first TCP port otherwise. Trap has no HTTP proxy. The ports of the modules follow,
     * as the Xdebug debugger one while it is on.
     *
     * @return array<non-empty-string, int>
     */
    private function ports(): array
    {
        $tcp = \array_map(\intval(...), $this->tcpPorts->ports);
        if ($tcp === []) {
            return [];
        }

        $pick = static fn(int $port): int => \in_array($port, $tcp, true) ? $port : $tcp[0];

        $ports = [
            'http' => $pick(\in_array($this->config->port, $tcp, true) ? $this->config->port : 8000),
            'smtp' => $pick(1025),
            'monolog' => $pick(9913),
            'var_dumper' => $pick(9912),
        ];
        foreach ($this->uiPorts as $class) {
            $ports += $this->container->get($class)->uiPorts();
        }

        return $ports;
    }

    private function debug(string $pattern, string ...$args): void
    {
        $this->logger->debug("[UI Service] $pattern", ...$args);
    }
}
