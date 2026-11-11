<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Internal\Driver;
use Buggregator\Trap\Client\Profiler\Internal\Recorded;
use Buggregator\Trap\Client\Profiler\Internal\Recording;

/**
 * The XHProf instrumenting profiler: ext-xhprof, or ext-tideways_xhprof when ext-xhprof is not loaded.
 *
 * Natively produces {@see Format::XHProf}.
 *
 * The extensions keep one profiling session per process. If something else already profiles the whole request
 * with them (XHGui, an `auto_prepend_file`), starting this driver takes that session over and stopping it ends
 * the session mid-request; the extensions offer no way to detect that.
 *
 * ```php
 * trap()::profile(new XHProf(memory: true, cpu: true))->run($job);
 * ```
 *
 * @api
 */
final class XHProf implements Driver
{
    /**
     * @param bool $memory Record memory usage and peak memory usage per call.
     * @param bool $cpu Record CPU time per call; adds noticeable overhead.
     * @param list<non-empty-string> $ignored Functions left out of the profile.
     *        ext-tideways_xhprof has no such option and profiles them anyway.
     */
    public function __construct(
        public readonly bool $memory = true,
        public readonly bool $cpu = false,
        public readonly array $ignored = [],
    ) {}

    /**
     * @internal
     */
    #[\Override]
    public function name(): string
    {
        return 'xhprof';
    }

    /**
     * @internal
     */
    #[\Override]
    public function isAvailable(): bool
    {
        return self::extension() !== null;
    }

    /**
     * @internal
     */
    #[\Override]
    public function formats(): array
    {
        return [Format::XHProf];
    }

    /**
     * @internal
     */
    #[\Override]
    public function options(): array
    {
        return [
            'extension' => self::extension(),
            'memory' => $this->memory,
            'cpu' => $this->cpu,
            'ignored' => $this->ignored,
        ];
    }

    /**
     * @internal
     */
    #[\Override]
    public function start(): Recording
    {
        $extension = self::extension() ?? throw new \LogicException('Neither ext-xhprof nor ext-tideways_xhprof is loaded.');
        if ($extension === 'xhprof') {
            xhprof_enable(
                ($this->memory ? \XHPROF_FLAGS_MEMORY : 0) | ($this->cpu ? \XHPROF_FLAGS_CPU : 0),
                $this->ignored === [] ? [] : ['ignored_functions' => $this->ignored],
            );
        } else {
            tideways_xhprof_enable(
                ($this->memory ? \TIDEWAYS_XHPROF_FLAGS_MEMORY : 0) | ($this->cpu ? \TIDEWAYS_XHPROF_FLAGS_CPU : 0),
            );
        }

        return new class($extension) implements Recording {
            public function __construct(
                private readonly string $extension,
            ) {}

            #[\Override]
            public function stop(): Recorded
            {
                /** @var array<string, array<string, int|float>> $data */
                $data = $this->extension === 'xhprof' ? xhprof_disable() : tideways_xhprof_disable();

                return new Recorded([
                    Format::XHProf->value => static fn(): string => \json_encode(
                        (object) $data,
                        \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION
                        | \JSON_INVALID_UTF8_SUBSTITUTE,
                    ),
                ], empty: $data === []);
            }
        };
    }

    /**
     * @return 'xhprof'|'tideways_xhprof'|null
     */
    private static function extension(): ?string
    {
        return match (true) {
            \function_exists('xhprof_enable') => 'xhprof',
            \function_exists('tideways_xhprof_enable') => 'tideways_xhprof',
            default => null,
        };
    }
}
