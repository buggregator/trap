<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler;

/**
 * What is known about a {@see Profile} besides its data.
 *
 * @api
 */
final class Metadata
{
    /**
     * @param array<non-empty-string, string> $tags
     * @param non-empty-string|null $appName From the `TRAP_PROFILER_APP_NAME` env variable.
     * @param string|null $file The file `run()` or `start()` was called in.
     * @param non-empty-string $driver
     * @param array<non-empty-string, scalar|list<string>|null> $options Driver options.
     * @param float $duration Seconds between the profiler start and stop.
     * @param int<0, max> $peakMemory Peak memory usage of the process in bytes when the profiler stopped.
     */
    public function __construct(
        public readonly ?string $name,
        public readonly array $tags,
        public readonly ?string $appName,
        public readonly ?string $hostname,
        public readonly \DateTimeImmutable $date,
        public readonly ?string $file,
        public readonly ?int $line,
        public readonly string $driver,
        public readonly array $options,
        public readonly float $duration,
        public readonly int $peakMemory,
    ) {}
}
