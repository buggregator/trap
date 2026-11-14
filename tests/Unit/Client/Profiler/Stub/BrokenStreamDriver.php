<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler\Stub;

use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\Internal\Driver;
use Buggregator\Trap\Client\Profiler\Internal\Recorded;
use Buggregator\Trap\Client\Profiler\Internal\Recording;

/**
 * A profiler whose document fails half way, as a trace removed while it is read.
 */
final class BrokenStreamDriver implements Driver
{
    #[\Override]
    public function name(): string
    {
        return 'broken';
    }

    #[\Override]
    public function isAvailable(): bool
    {
        return true;
    }

    #[\Override]
    public function formats(): array
    {
        return [Format::Spx];
    }

    #[\Override]
    public function options(): array
    {
        return [];
    }

    #[\Override]
    public function start(): Recording
    {
        return new class implements Recording {
            #[\Override]
            public function stop(): Recorded
            {
                return new Recorded([
                    Format::Spx->value => static fn(): array => [10, (static function (): \Generator {
                        yield 'half';
                        throw new \RuntimeException('The trace is gone.');
                    })()],
                ]);
            }
        };
    }
}
