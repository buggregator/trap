<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Proto\Frame;

/**
 * @internal
 */
final class FrameMapper
{
    /**
     * @param EventStorage $events The stored events: a Ray request adds its payloads to the event of its UUID.
     */
    public function __construct(
        private readonly EventStorage $events = new EventStorage(),
    ) {}

    /**
     * @return Event|null Null for frames the Buggregator UI has no event type for.
     */
    public function map(Frame $frame): ?Event
    {
        return match ($frame::class) {
            \Buggregator\Trap\Module\Tracing\Frame\OtlpTraces::class => null,
            \Buggregator\Trap\Module\VarDumper\Frame::class => (new \Buggregator\Trap\Module\VarDumper\Frontend\Mapper())->map($frame),
            Frame\Http::class => (new Mapper\HttpRequest())->map($frame),
            \Buggregator\Trap\Module\Mailer\Frame::class => (new \Buggregator\Trap\Module\Mailer\Frontend\Mapper())->map($frame),
            \Buggregator\Trap\Module\Sentry\Frame\SentryStore::class => (new \Buggregator\Trap\Module\Sentry\Frontend\StoreMapper())->map($frame),
            \Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope::class => (new \Buggregator\Trap\Module\Sentry\Frontend\EnvelopeMapper())->map($frame),
            \Buggregator\Trap\Module\Monolog\Frame::class => (new \Buggregator\Trap\Module\Monolog\Frontend\Mapper())->map($frame),
            \Buggregator\Trap\Module\Profiler\Frame::class => (new \Buggregator\Trap\Module\Profiler\Frontend\Mapper())->frameToEvent($frame),
            \Buggregator\Trap\Module\Ray\Frame::class => (new \Buggregator\Trap\Module\Ray\Frontend\Mapper($this->events))->map($frame),
            default => throw new \InvalidArgumentException('Unknown frame type ' . $frame::class),
        };
    }
}
