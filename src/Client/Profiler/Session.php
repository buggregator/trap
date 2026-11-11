<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Internal\Env;
use Buggregator\Trap\Client\Profiler\Internal\Recording;
use Buggregator\Trap\Client\Profiler\Internal\Settings;
use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Client\TrapHandle\StackTrace;

/**
 * Profiling started by {@see Profiling::start()}, running until {@see stop()}.
 *
 * Only one profile runs at a time in a process: a profile started while another one runs is skipped.
 * A session that is never stopped is stopped and delivered when destroyed, with all errors dropped.
 *
 * @api
 */
final class Session
{
    /** Whether any profile is running; profilers can't nest. */
    private static bool $running = false;

    /** @var array<non-empty-string, true> */
    private static array $warned = [];

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
            self::warn('nested', 'A profile is already running, and profiles can not nest: the inner one is skipped.');
            return new self($settings, null);
        }

        if (!$settings->shouldProfile()) {
            return new self($settings, null);
        }

        $driver = $settings->driver;
        if ($driver === null || !$driver->isAvailable()) {
            $requested = $settings->requested;
            $requested === null
                ? self::warn('unavailable', 'No profiler is available: install ext-excimer, ext-xhprof or ext-tideways_xhprof. The code runs without profiling.')
                : self::warn(
                    'unavailable:' . $requested->name(),
                    \sprintf('The `%s` profiler is not available. The code runs without profiling.', $requested->name()),
                );
            return new self($settings, null);
        }

        $site = StackTrace::callSite();
        $recording = $driver->start();
        self::$running = true;

        return new self($settings, $recording, $site['file'] ?? null, $site['line'] ?? null);
    }

    /**
     * Stops profiling, delivers the profile and returns it.
     *
     * Every destination runs; the first error of a file or custom destination is thrown after them.
     * Later calls return the same profile without delivering it again.
     *
     * @return Profile|null Null when nothing was profiled, and then nothing is delivered: the condition was false,
     *         the sample missed, no profiler is available, another profile was running, or the profile is empty
     *         (a run shorter than the sampling period).
     */
    public function stop(): ?Profile
    {
        return $this->finish(quiet: false);
    }

    /**
     * @param bool $quiet Drop the errors of the profiler and of the destinations.
     *
     * @internal
     */
    public function finish(bool $quiet): ?Profile
    {
        if ($this->stopped) {
            return $this->profile;
        }

        $this->stopped = true;
        $recording = $this->recording;
        if ($recording === null) {
            return null;
        }

        $duration = (float) (\hrtime(true) - $this->startedAt) / 1e9;
        $this->recording = null;
        try {
            $recorded = $recording->stop();
        } catch (\Throwable $e) {
            return $quiet ? null : throw $e;
        } finally {
            self::$running = false;
        }

        $driver = $this->settings->driver;
        \assert($driver !== null);

        if ($recorded->isEmpty()) {
            self::warn('empty:' . $driver->name(), \sprintf(
                'The `%s` profile is empty and is not delivered: a run shorter than the sampling period gets no samples. '
                . 'Try a smaller `period`.',
                $driver->name(),
            ));
            return null;
        }

        $appName = Env::get('TRAP_PROFILER_APP_NAME');
        $hostname = \gethostname();

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

        $this->settings->deliver($this->profile, $quiet);

        return $this->profile;
    }

    public function __destruct()
    {
        try {
            $this->finish(quiet: true);
        } catch (\Throwable) {
            // No exception may leave a destructor.
        }
    }

    /**
     * Dumps the message to Trap once per process.
     *
     * @param non-empty-string $key
     */
    private static function warn(string $key, string $message): void
    {
        if (isset(self::$warned[$key])) {
            return;
        }

        self::$warned[$key] = true;
        /** @psalm-suppress InternalMethod */
        TrapHandle::fromArray(['Profiler' => $message]);
    }
}
