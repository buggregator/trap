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
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Logger;

/**
 * @internal
 */
final class Service
{
    /**
     * Periods of the event list in the UI, in seconds.
     */
    private const WINDOWS = [
        '1h' => 3600,
        '3h' => 10800,
        '6h' => 21600,
        '12h' => 43200,
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    /** @var list<non-empty-string> UI event types the modules produce */
    private readonly array $eventTypes;

    public function __construct(
        private readonly Logger $logger,
        private readonly EventStore $eventsStorage,
        Modules $modules,
        private readonly FrontendConfig $config,
        private readonly TcpPorts $tcpPorts,
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
        $this->eventsStorage->delete($uuid);
        return new Success();
    }

    #[RegexpRoute(Method::Get, '#^api/event/(?<uuid>[a-f0-9-]++)$#i')]
    #[AssertSuccess(Method::Get, 'api/event/0145a0e0-0b1a-4e4a-9b1a', ['uuid' => '0145a0e0-0b1a-4e4a-9b1a']),
        AssertFail(Method::Get, 'api/event/foo-bar-baz'),
        AssertFail(Method::Get, 'api/event/'),]
    public function eventShow(string $uuid): UiEvent|Error
    {
        $this->debug('Show event %s', $uuid);
        $event = $this->eventsStorage->get($uuid);
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
        return $this->eventsStorage->pin($uuid) ? new Success() : Error::notFound('Event not found');
    }

    #[RegexpRoute(Method::Delete, '#^api/event/(?<uuid>[a-f0-9-]++)/pin$#i')]
    #[AssertSuccess(Method::Delete, 'api/event/0145a0e0-0b1a-4e4a-9b1a/pin', ['uuid' => '0145a0e0-0b1a-4e4a-9b1a'])]
    public function eventUnpin(string $uuid): Success|Error
    {
        $this->debug('Unpin event %s', $uuid);
        return $this->eventsStorage->pin($uuid, false) ? new Success() : Error::notFound('Event not found');
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
                    $this->eventsStorage->delete($uuid);
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
    public function eventsList(?string $project = null, ?string $window = null, int|string $limit = 0): EventCollection
    {
        $this->debug('List events of project %s', $project ?? 'any');
        $period = self::WINDOWS[$window ?? ''] ?? null;
        $since = $period === null ? null : \microtime(true) - (float) $period;
        $limit = \max(0, (int) $limit);

        $events = [];
        foreach ($this->eventsStorage as $event) {
            if ($since !== null && $event->timestamp < $since) {
                // From the newest to the oldest one
                break;
            }

            ($project === null || $project === '' || $event->projectKey() === $project)
                and $events[] = UiEvent::of($event, $this->eventsStorage);

            if ($limit > 0 && \count($events) >= $limit) {
                break;
            }
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

        return new Settings($this->eventTypes, $this->ports());
    }

    /**
     * Ports for the setup guide of the UI. Every TCP port of Trap takes any protocol: a usual port is shown
     * when Trap listens on it, the first TCP port otherwise. Trap has no HTTP proxy and no Xdebug listener.
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

        return [
            'http' => $pick(\in_array($this->config->port, $tcp, true) ? $this->config->port : 8000),
            'smtp' => $pick(1025),
            'monolog' => $pick(9913),
            'var_dumper' => $pick(9912),
        ];
    }

    private function debug(string $pattern, string ...$args): void
    {
        $this->logger->debug("[UI Service] $pattern", ...$args);
    }
}
