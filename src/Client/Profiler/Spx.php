<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Internal\Driver;
use Buggregator\Trap\Client\Profiler\Internal\Model\PprofWriter;
use Buggregator\Trap\Client\Profiler\Internal\Model\SpeedscopeWriter;
use Buggregator\Trap\Client\Profiler\Internal\Model\SpxReport;
use Buggregator\Trap\Client\Profiler\Internal\Model\XHProfWriter;
use Buggregator\Trap\Client\Profiler\Internal\Recorded;
use Buggregator\Trap\Client\Profiler\Internal\Recording;
use Buggregator\Trap\Client\Profiler\Internal\SpxExtension;
use Buggregator\Trap\Client\Profiler\Internal\Warnings;

/**
 * The SPX profiler (ext-spx; Linux, macOS and FreeBSD, Windows unofficially), started and stopped around
 * the profiled code.
 *
 * SPX reads its settings when the process or the request starts, so they can't be set from here:
 * `SPX_ENABLED=1`, `SPX_AUTO_START=0` and, for a CLI script, `SPX_REPORT=full` must be in the environment
 * (or, for a web request, in the `SPX_*` cookies, headers or query parameters of a client SPX grants the access,
 * or the `spx.http_profiling_*` INI settings). A CLI script without them, or a data directory SPX can't write to,
 * leaves the driver unavailable with a warning; a request SPX doesn't profile gets a warning when it stops.
 * The other SPX settings, such as `SPX_METRICS` and `SPX_SAMPLING_PERIOD`, are taken from there too.
 *
 * ```sh
 * SPX_ENABLED=1 SPX_AUTO_START=0 SPX_REPORT=full php script.php
 * ```
 *
 * ```php
 * trap()::profile(new Spx())->run($job);
 * ```
 *
 * SPX writes a full report into `spx.data_dir`; unless `$keepFiles`, the driver takes it out of the way of a Trap
 * that observes the directory when profiling stops, and deletes it once the profile is delivered and no longer used.
 * A report no destination took is put back, and a warning tells where. A trace over 64 MB is not delivered.
 * The trace is read in chunks.
 * Natively it produces {@see Format::Spx}, sent to Trap as is, with the name and the tags
 * of the profile as the custom metadata of the report, for the SPX web UI to show. With ext-zlib it converts
 * to {@see Format::XHProf}, {@see Format::Speedscope}, {@see Format::Collapsed} and {@see Format::Pprof}
 * with the wall and CPU time and the calls; in the sampling mode, the calls SPX saw at its ticks.
 *
 * The profile spans from {@see Profiling::run()} or {@see Profiling::start()} to the stop, and holds the calls
 * that were open at the start too, as SPX records them.
 *
 * @api
 */
final class Spx implements Driver
{
    /**
     * @internal
     */
    public const NO_REPORT = 'SPX made no report: it may not be enabled for this process or request, deny the request'
        . ' the access, be unable to write to `spx.data_dir`, run another span, or have failed to start.';

    /** Bytes of custom metadata SPX takes at most. */
    private const MAX_CUSTOM_METADATA = 4096;

    /**
     * @param bool $keepFiles Keep the report in `spx.data_dir`, to see it in the SPX web UI too.
     */
    public function __construct(
        public readonly bool $keepFiles = false,
    ) {}

    /**
     * @internal
     *
     * @param positive-int|null $samplingPeriod Microseconds between the samples when SPX sampled the stack.
     */
    public static function recorded(SpxReport $report, ?int $samplingPeriod = null): Recorded
    {
        $encoders = [
            Format::Spx->value => static fn(?Metadata $metadata = null): array => $report->archive(
                $metadata instanceof Metadata ? self::customMetadata($metadata) : null,
            ),
        ];
        if (\function_exists('inflate_init')) {
            $metadata = $report->metadata;
            $encoders += [
                Format::XHProf->value => static fn(): string => XHProfWriter::json($report->tree()->toGraph()),
                Format::Speedscope->value => static fn(): string => SpeedscopeWriter::json(
                    $report->tree(),
                    $report->key,
                    'buggregator/trap',
                ),
                Format::Collapsed->value => static fn(): string => SpeedscopeWriter::collapsed($report->tree()),
                Format::Pprof->value => static fn(): string => PprofWriter::encode(
                    $report->tree(),
                    $samplingPeriod === null ? 0.0 : (float) $samplingPeriod / 1e6,
                    false,
                    \max(0, ($metadata->int('exec_ts') ?? 0) * 1_000_000_000),
                    // SPX names it `wall_time_ms`, but it holds microseconds
                    \max(0, ($metadata->int('wall_time_ms') ?? 0) * 1000),
                    // A sampled SPX trace replays only the changes of the stack at each tick: a call that stays
                    // on the stack counts once, so the counts are neither samples nor calls
                    countType: $samplingPeriod === null ? 'calls' : 'sampled_calls',
                ),
            ];
        }

        return new Recorded($encoders, empty: $report->isEmpty(), settle: static function (bool $delivered) use ($report): void {
            $warning = $report->settle($delivered);
            $warning === null or Warnings::once("spx:kept:{$report->key}", $warning, ['driver' => 'spx', 'reason' => 'kept']);
        });
    }

    /**
     * @internal
     */
    #[\Override]
    public function name(): string
    {
        return 'spx';
    }

    /**
     * Warns what the process misses when SPX is loaded but can't be started.
     *
     * @internal
     */
    #[\Override]
    public function isAvailable(): bool
    {
        $extension = SpxExtension::get();
        if (!$extension->isLoaded()) {
            return false;
        }

        $dir = $extension->dataDir();
        // SPX creates the directory, but not its parents
        $problem = $extension->config()->problem()
            ?? ((\is_dir($dir) ? \is_writable($dir) : \is_writable(\dirname($dir))) ? null : "SPX can't write its report to `$dir`.");
        $problem === null or Warnings::once(
            'spx:config',
            "$problem The code runs without profiling.",
            ['driver' => $this->name(), 'reason' => 'config'],
        );

        return $problem === null;
    }

    /**
     * @internal
     */
    #[\Override]
    public function formats(): array
    {
        return \function_exists('inflate_init')
            ? [Format::Spx, Format::XHProf, Format::Speedscope, Format::Collapsed, Format::Pprof]
            : [Format::Spx];
    }

    /**
     * @internal
     */
    #[\Override]
    public function options(): array
    {
        $extension = SpxExtension::get();

        return [
            'sampling_period' => $extension->config()->samplingPeriod(),
            'data_dir' => $extension->dataDir(),
            'keep_files' => $this->keepFiles,
        ];
    }

    /**
     * @internal
     */
    #[\Override]
    public function start(): Recording
    {
        $extension = SpxExtension::get();
        // Made before SPX starts, which would trace the autoloading of its classes too
        $recording = new class($extension, $this->keepFiles, $extension->config()->samplingPeriod()) implements Recording {
            /**
             * @param positive-int|null $samplingPeriod
             */
            public function __construct(
                private readonly SpxExtension $extension,
                private readonly bool $keepFiles,
                private readonly ?int $samplingPeriod,
            ) {}

            /**
             * SPX tells nothing when it doesn't start, and returns no report key on stop then.
             */
            #[\Override]
            public function stop(): Recorded
            {
                $key = $this->extension->stop();
                \is_string($key) && $key !== '' or throw new \RuntimeException(Spx::NO_REPORT);

                return Spx::recorded(
                    SpxReport::read($this->extension->dataDir(), $key, $this->keepFiles),
                    $this->samplingPeriod,
                );
            }
        };
        $extension->start();

        return $recording;
    }

    /**
     * The name and the tags of the profile as the custom metadata of the report, which the SPX web UI shows,
     * within the 4 KB SPX takes.
     */
    private static function customMetadata(Metadata $metadata): ?string
    {
        $parts = $metadata->name === null ? [] : ["name={$metadata->name}"];
        foreach ($metadata->tags as $key => $value) {
            $parts[] = "$key=$value";
        }

        return $parts === [] ? null : \mb_strcut(\implode(', ', $parts), 0, self::MAX_CUSTOM_METADATA, 'UTF-8');
    }
}
