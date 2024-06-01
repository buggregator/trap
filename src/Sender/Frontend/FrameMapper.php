<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend;

use Buggregator\Trap\Proto\Frame;

/**
 * @internal
 */
final class FrameMapper
{
    public function map(Frame $frame): Event
    {
        return match ($frame::class) {
            Frame\VarDumper::class => (new Mapper\VarDump())->map($frame),
            Frame\Http::class => (new Mapper\HttpRequest())->map($frame),
            Frame\Smtp::class => (new Mapper\Smtp())->map($frame),
            Frame\Sentry\SentryStore::class => (new Mapper\SentryStore())->map($frame),
            Frame\Sentry\SentryEnvelope::class => (new Mapper\SentryEnvelope())->map($frame),
            Frame\Monolog::class => (new Mapper\Monolog())->map($frame),
            Frame\Profiler::class => (new Mapper\Profiler())->map($frame),
            default => throw new \InvalidArgumentException('Unknown frame type ' . $frame::class),
        };
    }
}
