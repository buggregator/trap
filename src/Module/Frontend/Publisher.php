<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Push;
use Buggregator\Trap\Server\Traffic\Websocket\Frame;
use Buggregator\Trap\Support\Json;

/**
 * Publishes messages to the WebSocket clients of the UI: `{event, data}` in a channel.
 *
 * The UI listens to the publications of every channel the server subscribes it to, and takes those of
 * the project channel of the project it shows, `events.project.{key}`.
 *
 * A container singleton.
 *
 * @internal
 */
final class Publisher
{
    public const PROJECT_CHANNEL_PREFIX = 'events.project.';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return non-empty-string
     */
    public static function projectChannel(string $project): string
    {
        return self::PROJECT_CHANNEL_PREFIX . $project;
    }

    /**
     * @param non-empty-string $channel
     * @param non-empty-string $event Name of the message, as `event.received`.
     */
    public function publish(string $channel, string $event, mixed $data = null): void
    {
        $this->connectionPool->send(Frame::text(Json::encode(new Push($event, $channel, $data))));
    }

    /**
     * @param non-empty-string $event
     */
    public function toProject(string $project, string $event, mixed $data = null): void
    {
        $this->publish(self::projectChannel($project), $event, $data);
    }

    /**
     * Publishes to every client at once.
     *
     * @param non-empty-string $event
     */
    public function toAllProjects(string $event, mixed $data = null): void
    {
        $this->publish('events', $event, $data);
    }
}
