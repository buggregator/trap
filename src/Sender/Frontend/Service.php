<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend;

use Buggregator\Trap\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Sender\Frontend\Event\AttachedFile;
use Buggregator\Trap\Sender\Frontend\Message\Attachments;
use Buggregator\Trap\Sender\Frontend\Message\EventCollection;
use Buggregator\Trap\Sender\Frontend\Message\Settings;
use Buggregator\Trap\Sender\Frontend\Message\Success;
use Buggregator\Trap\Sender\Frontend\Message\Version;

/**
 * @internal
 */
final class Service
{
    public function __construct(
        private readonly Logger $logger,
        private readonly EventStorage $eventsStorage,
    ) {}

    #[StaticRoute(Method::Get, 'api/version')]
    public function version(): Version
    {
        $this->debug('Get version');
        return new Version();
    }

    #[RegexpRoute(Method::Delete, '#^api/event/(?<uuid>[a-f0-9-]++)$#i')]
    #[
        AssertSuccess(Method::Delete, 'api/event/0145a0e0-0b1a-4e4a-9b1a', ['uuid' => '0145a0e0-0b1a-4e4a-9b1a']),
        AssertFail(Method::Delete, 'api/event/foo-bar-baz'),
        AssertFail(Method::Delete, 'api/event/'),
    ]
    public function eventDelete(string $uuid): Success
    {
        $this->debug('Delete event %s', $uuid);
        $this->eventsStorage->delete($uuid);
        return new Success();
    }

    #[RegexpRoute(Method::Get, '#^api/event/(?<uuid>[a-f0-9-]++)$#i')]
    #[
        AssertSuccess(Method::Get, 'api/event/0145a0e0-0b1a-4e4a-9b1a', ['uuid' => '0145a0e0-0b1a-4e4a-9b1a']),
        AssertFail(Method::Get, 'api/event/foo-bar-baz'),
        AssertFail(Method::Get, 'api/event/'),
    ]
    public function eventShow(string $uuid): Event|Success
    {
        $this->debug('Show event %s', $uuid);
        $event = $this->eventsStorage->get($uuid);
        return $event ?? new Success(status: false);
    }

    #[RegexpRoute(Method::Get, '#^api/smtp/(?<uuid>[a-f0-9-]++)/attachments$#i')]
    #[
        AssertSuccess(Method::Get, 'api/smtp/018ff30e-a452-7316-b60f-a2d1c3fe16ab/attachments', [
            'uuid' => '018ff30e-a452-7316-b60f-a2d1c3fe16ab',
        ]),
    ]
    public function smtpAttachments(string $uuid): Attachments|Success
    {
        $this->debug('Show SMTP %s attachments', $uuid);
        $event = $this->eventsStorage->get($uuid);

        if ($event === null) {
            return new Success(status: false);
        }

        $attaches = [];
        foreach ($event->assets ?? [] as $attachment) {
            $attachment instanceof AttachedFile and $attaches[] = $attachment;
        }

        return new Attachments($event, files: $attaches);
    }

    #[StaticRoute(Method::Delete, 'api/events')]
    #[
        AssertFail(Method::Delete, '/api/events'),
        AssertFail(Method::Delete, 'api/events/'),
        AssertFail(Method::Delete, 'api/event'),
    ]
    public function eventsDelete(array $uuids = []): Success
    {
        $this->debug('Delete all events');
        if (empty($uuids)) {
            $this->eventsStorage->clear();
            return new Success();
        }

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

    #[StaticRoute(Method::Get, 'api/events')]
    #[
        AssertFail(Method::Get, 'api/event'),
        AssertFail(Method::Post, 'api/events'),
        AssertFail(Method::Get, '/api/events'),
    ]
    public function eventsList(): EventCollection
    {
        $this->debug('List all events');
        return new EventCollection(events: \iterator_to_array($this->eventsStorage->getIterator(), false));
    }

    #[StaticRoute(Method::Get, 'api/settings')]
    #[
        AssertFail(Method::Get, 'api/setting'),
        AssertFail(Method::Post, 'api/settings'),
        AssertFail(Method::Get, '/api/settings'),
    ]
    public function settings(): Settings
    {
        $this->debug('List settings');
        return new Settings();
    }

    private function debug(string $pattern, string ...$args): void
    {
        $this->logger->debug("[UI Service] $pattern", ...$args);
    }
}
