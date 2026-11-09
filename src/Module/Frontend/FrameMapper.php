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
            \Buggregator\Trap\Module\VarDumper\Frame::class => (new \Buggregator\Trap\Module\VarDumper\Frontend\Mapper())->map($frame),
            Frame\Http::class => (new Mapper\HttpRequest())->map($frame),
            Frame\Smtp::class => (new Mapper\Smtp())->map($frame),
            Frame\Sentry\SentryStore::class => (new Mapper\SentryStore())->map($frame),
            Frame\Sentry\SentryEnvelope::class => (new Mapper\SentryEnvelope())->map($frame),
            \Buggregator\Trap\Module\Monolog\Frame::class => (new \Buggregator\Trap\Module\Monolog\Frontend\Mapper())->map($frame),
            Frame\Profiler::class => (new Module\Profiler\Mapper())->frameToEvent($frame),
            default => throw new \InvalidArgumentException('Unknown frame type ' . $frame::class),
        };
    }
}
