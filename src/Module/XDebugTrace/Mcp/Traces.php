<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Mcp;

use Buggregator\Trap\Module\XDebugTrace\Config\Traces as Config;
use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Module\XDebugTrace\Trace\CallTree;
use Buggregator\Trap\Module\XDebugTrace\TraceStorage;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Support\Json;
use Buggregator\Trap\Support\TimeSlice;

/**
 * The traces as the MCP tools find them: by the UUID of the event, with errors the model can act on.
 *
 * @internal
 */
final class Traces
{
    /** Calls per answer by default: agents read smaller pages than the UI */
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly EventStore $events,
        private readonly TraceStorage $storage,
        private readonly Config $config,
    ) {}

    /**
     * The number argument of the name, or the default when it is absent.
     *
     * @throws \InvalidArgumentException
     */
    public static function int(array $arguments, string $name, ?int $default = null): ?int
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        \is_int($value) && $value >= 0 or throw new \InvalidArgumentException("`$name` must be a whole number.");

        return $value;
    }

    /**
     * A JSON object as the tools return it.
     *
     * @return array<array-key, mixed>
     */
    public static function object(mixed $value): array
    {
        /** @var array<array-key, mixed> */
        return Json::decode(Json::encode($value));
    }

    /**
     * @return array{file: string, name: string, url: string, size: int, compressed: bool, summary: array}
     *
     * @throws \InvalidArgumentException
     */
    public function payload(array $arguments): array
    {
        $event = $this->event($arguments);
        /** @var array{file: string, name: string, url: string, size: int, compressed: bool, summary: array} */
        return $event->payload;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function tree(array $arguments): CallTree
    {
        $event = $this->event($arguments);
        $path = $this->storage->path($event->uuid) ?? throw new \InvalidArgumentException(
            "The file of trace `$event->uuid` is gone: it was pushed out by newer traces.",
        );

        return new CallTree($path, $this->config->maxSizeBytes(), new TimeSlice());
    }

    /**
     * @return Event<array>
     *
     * @throws \InvalidArgumentException
     */
    private function event(array $arguments): Event
    {
        /** @var mixed $uuid */
        $uuid = $arguments['uuid'] ?? null;
        \is_string($uuid) && $uuid !== '' or throw new \InvalidArgumentException('`uuid` must be a non-empty string.');
        $event = $this->events->get($uuid);
        $event !== null && $event->type === Frame::TYPE && \is_array($event->payload) or throw new \InvalidArgumentException(
            "No Xdebug trace with UUID `$uuid`. List them with `list_events` and the type `xdebug-trace`.",
        );

        /** @var Event<array> $event */
        return $event;
    }
}
