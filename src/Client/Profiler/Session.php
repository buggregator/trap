<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Internal\Driver;
use Buggregator\Trap\Client\Profiler\Internal\Env;
use Buggregator\Trap\Client\Profiler\Internal\Recording;
use Buggregator\Trap\Client\Profiler\Internal\Settings;
use Buggregator\Trap\Client\Profiler\Internal\Warnings;
use Buggregator\Trap\Client\TrapHandle\StackTrace;

/**
 * Profiling started by {@see Profiling::start()}, running until {@see stop()}.
 *
 * Only one profile runs at a time in a process: a profile started while another one runs is skipped.
 * A session that is never stopped is stopped and delivered when destroyed.
 *
 * @api
 */
final class Session
{
    /** Whether any profile is running; profilers can't nest. */
    private static bool $running = false;

    private bool $stopped = false;
    private ?Profile $profile = null;
    private readonly \DateTimeImmutable $date;
    private readonly int $startedAt;

    /**
     * @param Recording|null $recording Null when profiling was skipped.
     */
    private function __construct(
        private readonly Settings $settings,
        private ?Recording $recording,
        private readonly ?string $file = null,
        private readonly ?int $line = null,
    ) {
        $this->date = new \DateTimeImmutable();
        $this->startedAt = \hrtime(true);
    }

    /**
     * @internal Use {@see Profiling::start()}.
     */
    public static function open(Settings $settings): self
    {
        // Checked first, so that a nested profile is skipped whatever the conditions and the extensions.
        if (self::$running) {
            Warnings::once(
                'nested',
                'A profile is already running, and profiles can not nest: the inner one is skipped.',
                ['reason' => 'nested'],
            );
            return new self($settings, null);
        }

        if (!$settings->shouldProfile()) {
            return new self($settings, null);
        }

        $driver = $settings->driver;
        if (!$driver instanceof Driver || !$driver->isAvailable()) {
            $requested = $settings->requested;
            $requested instanceof Driver
                ? Warnings::once(
                    'unavailable:' . $requested->name(),
                    \sprintf('The `%s` profiler is not available. The code runs without profiling.', $requested->name()),
                    ['driver' => $requested->name(), 'reason' => 'unavailable'],
                )
                : Warnings::once(
                    'unavailable',
                    'No profiler is available: install ext-excimer, ext-xhprof or ext-tideways_xhprof. The code runs without profiling.',
                    ['reason' => 'unavailable'],
                );
            return new self($settings, null);
        }

        $site = StackTrace::callSite();
        try {
            $recording = $driver->start();
        } catch (\Throwable $throwable) {
            // E.g. a clock the system lacks, or a warning that an error handler turned into an exception.
            Warnings::once(
                'start:' . $driver->name(),
                \sprintf('The `%s` profiler failed to start: %s The code runs without profiling.', $driver->name(), $throwable->getMessage()),
                ['driver' => $driver->name(), 'reason' => 'start', 'exception' => $throwable],
            );
            return new self($settings, null);
        }

        self::$running = true;

        return new self($settings, $recording, $site['file'] ?? null, $site['line'] ?? null);
    }

    /**
     * Stops profiling, delivers the profile and returns it.
     *
     * Errors of the profiler and of the destinations are logged, never thrown.
     * Later calls return the same profile without delivering it again.
     *
     * @return Profile|null Null when nothing was profiled, and then nothing is delivered: the condition was false,
     *         the sample missed, no profiler is available, another profile was running, or the profile is empty
     *         (a run shorter than the sampling period).
     */
    public function stop(): ?Profile
    {
        return $this->finish();
    }

    /**
     * @internal
     */
    public function finish(): ?Profile
    {
        if ($this->stopped) {
            return $this->profile;
        }

        $this->stopped = true;
        $recording = $this->recording;
        if (!$recording instanceof Recording) {
            return null;
        }

        $duration = (float) (\hrtime(true) - $this->startedAt) / 1e9;
        $this->recording = null;
        try {
            $recorded = $recording->stop();
        } catch (\Throwable $throwable) {
            Warnings::error(\sprintf('The `%s` profiler failed to stop', (string) $this->settings->driver?->name()), $throwable);
            return null;
        } finally {
            self::$running = false;
        }

        $driver = $this->settings->driver;
        \assert($driver instanceof Driver);

        if ($recorded->isEmpty()) {
            Warnings::once('empty:' . $driver->name(), \sprintf(
                'The `%s` profile is empty and is not delivered: a run shorter than the sampling period gets no samples. '
                . 'Try a smaller `period`.',
                $driver->name(),
            ), ['driver' => $driver->name(), 'reason' => 'empty']);
            // Nothing to keep: what the profiler wrote is dropped as if delivered
            $recorded->settle(true);
            return null;
        }

        try {
            $appName = Env::get('TRAP_PROFILER_APP_NAME');
            $hostname = @\gethostname();

            /** @psalm-suppress InternalMethod */
            $this->profile = new Profile($recorded, new Metadata(
                name: $this->settings->name,
                tags: $this->settings->tags,
                appName: $appName === '' ? null : $appName,
                hostname: $hostname === false ? null : $hostname,
                date: $this->date,
                file: $this->file,
                line: $this->line,
                driver: $driver->name(),
                options: $driver->options(),
                duration: $duration,
                peakMemory: \max(0, \memory_get_peak_usage()),
            ));
        } catch (\Throwable $throwable) {
            Warnings::error(\sprintf('The `%s` profile was not made', $driver->name()), $throwable);
            return null;
        }

        $recorded->settle($this->settings->deliver($this->profile));

        return $this->profile;
    }

    public function __destruct()
    {
        try {
            $this->finish();
        } catch (\Throwable) {
            // No exception may leave a destructor.
        }
    }
}
