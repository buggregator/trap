<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Internal\Driver;
use Buggregator\Trap\Client\Profiler\Internal\Model\SpeedscopeReader;
use Buggregator\Trap\Client\Profiler\Internal\Model\XHProfWriter;
use Buggregator\Trap\Client\Profiler\Internal\Recorded;
use Buggregator\Trap\Client\Profiler\Internal\Recording;

/**
 * The Excimer sampling profiler (ext-excimer; Linux, macOS and BSD only).
 *
 * Natively produces {@see Format::Speedscope} and {@see Format::Collapsed}; converts to {@see Format::XHProf}
 * with the samples as `ct` (calls are not measured) and the sampled time as `wt` (and `cpu` with {@see Clock::Cpu}).
 *
 * ```php
 * trap()::profile(new Excimer(period: 0.001, clock: Clock::Cpu))->run($job);
 * ```
 *
 * @api
 */
final class Excimer implements Driver
{
    /**
     * @param float $period Sampling period in seconds.
     * @param int|null $maxDepth Positive. Deeper stack frames are truncated; null keeps the extension default.
     */
    public function __construct(
        public readonly float $period = 0.001,
        public readonly Clock $clock = Clock::Wall,
        public readonly ?int $maxDepth = null,
    ) {
        $period > 0 or throw new \InvalidArgumentException('The sampling period must be positive.');
        $maxDepth === null || $maxDepth > 0 or throw new \InvalidArgumentException('The max depth must be positive.');
    }

    /**
     * @internal
     */
    #[\Override]
    public function name(): string
    {
        return 'excimer';
    }

    /**
     * @internal
     */
    #[\Override]
    public function isAvailable(): bool
    {
        return \extension_loaded('excimer') && \class_exists(\ExcimerProfiler::class, false);
    }

    /**
     * @internal
     */
    #[\Override]
    public function formats(): array
    {
        return [Format::Speedscope, Format::Collapsed, Format::XHProf];
    }

    /**
     * @internal
     */
    #[\Override]
    public function options(): array
    {
        return [
            'period' => $this->period,
            'clock' => $this->clock->value,
            'max_depth' => $this->maxDepth,
        ];
    }

    /**
     * @internal
     */
    #[\Override]
    public function start(): Recording
    {
        $profiler = new \ExcimerProfiler();
        $profiler->setPeriod($this->period);
        $profiler->setEventType($this->clock === Clock::Cpu ? \EXCIMER_CPU : \EXCIMER_REAL);
        $this->maxDepth === null or $profiler->setMaxDepth($this->maxDepth);
        $profiler->start();

        return new class($profiler, $this->period, $this->clock === Clock::Cpu) implements Recording {
            public function __construct(
                private readonly \ExcimerProfiler $profiler,
                private readonly float $period,
                private readonly bool $cpu,
            ) {}

            #[\Override]
            public function stop(): Recorded
            {
                $this->profiler->stop();
                $log = $this->profiler->getLog();
                $period = $this->period;
                $cpu = $this->cpu;

                // Excimer copies names and paths byte for byte, so they need not be valid UTF-8.
                return new Recorded([
                    Format::Speedscope->value => static fn(): string => \json_encode(
                        $log->getSpeedscopeData(),
                        \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
                    ),
                    Format::Collapsed->value => $log->formatCollapsed(...),
                    Format::XHProf->value => static fn(): string => XHProfWriter::json(
                        SpeedscopeReader::read($log->getSpeedscopeData(), $period, $cpu)->toGraph(),
                    ),
                ], empty: \count($log) === 0);
            }
        };
    }
}
