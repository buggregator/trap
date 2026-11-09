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
     * @return Event|null Null for frames the Buggregator UI has no event type for.
     */
    public function map(Frame $frame): ?Event
    {
        return match ($frame::class) {
            Frame\Otlp\OtlpTraces::class => null,
            Frame\VarDumper::class => (new Mapper\VarDump())->map($frame),
            Frame\Http::class => (new Mapper\HttpRequest())->map($frame),
            Frame\Smtp::class => (new Mapper\Smtp())->map($frame),
            \Buggregator\Trap\Module\Sentry\Frame\SentryStore::class => (new \Buggregator\Trap\Module\Sentry\Frontend\StoreMapper())->map($frame),
            \Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope::class => (new \Buggregator\Trap\Module\Sentry\Frontend\EnvelopeMapper())->map($frame),
            Frame\Monolog::class => (new Mapper\Monolog())->map($frame),
            \Buggregator\Trap\Module\Profiler\Frame::class => (new \Buggregator\Trap\Module\Profiler\Frontend\Mapper())->frameToEvent($frame),
            default => throw new \InvalidArgumentException('Unknown frame type ' . $frame::class),
        };
    }
}
