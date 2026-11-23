<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Cron;

/**
 * Cron monitors and their runs, by project: Sentry Crons check-ins and ping URLs.
 *
 * A check-in makes its monitor, opens a run (`in_progress`) or closes one (`ok`, `error`) by its check-in ID, and
 * moves the next expected slot. {@see check()} marks what did not come: a run in progress past its max runtime
 * times out, and a slot without a check-in within the margin is missed, or unknown when it fell while Trap was
 * not running (the machine slept). No slot is missed while a run is in progress: a long run may span a slot,
 * and the max runtime covers one that hangs.
 *
 * Memory is bounded: {@see self::MAX_MONITORS} monitors, the least recently active one goes for a new one,
 * the newest {@see self::MAX_CHECK_INS} runs of each, and {@see self::MAX_OUTPUT_BYTES} of ping output in all,
 * above which the oldest runs lose theirs.
 *
 * @internal
 */
final class Crons
{
    public const MAX_MONITORS = 200;
    public const MAX_CHECK_INS = 100;
    public const MAX_OUTPUT_BYTES = 8 * 1024 * 1024;

    /** Seconds between two checks that mean Trap was not running in between */
    public const DOWNTIME_GAP = 30.0;

    /** Seconds a check-in may come before its slot and still be the run of that slot: the clock of its host may run ahead */
    public const EARLY = 60.0;

    private const MAX_DOWNTIMES = 20;

    /** Missed slots one check writes per monitor; the next slot comes after now then */
    private const MAX_MISSED = 100;

    /** @var array<non-empty-string, Monitor> */
    private array $monitors = [];

    /** @var int<0, max> */
    private int $seq = 0;

    private ?float $lastCheck = null;

    /** @var list<array{float, float}> Spans of time without checks */
    private array $downtimes = [];

    /**
     * @param \Closure(non-empty-string): void $changed Told the project of a change {@see check()} or
     *        {@see delete()} made.
     */
    public function __construct(
        private readonly \Closure $changed,
    ) {}

    /**
     * @return non-empty-string The ID of the run.
     */
    public function record(CheckInMessage $message): string
    {
        $now = $message->time;
        $monitor = $this->monitor($message->project, $message->slug) ?? $this->create($message);
        $message->config === null or $monitor->configure($message->config);

        $run = $this->runOf($monitor, $message);
        if ($run === null) {
            $run = $this->open($monitor, $message);
        } elseif ($message->status === CheckInMessage::IN_PROGRESS) {
            // Sentry's heartbeat: a run still in progress gets its max runtime again
            $run->status === CheckInMessage::IN_PROGRESS and $run->timeoutAt = $now + (float) ($run->maxRuntime * 60);
        } elseif ($run->finishedAt === null) {
            // A run Trap timed out keeps the status, and shows when it finished
            $run->status === CheckIn::TIMEOUT or $run->status = $message->status;
            $run->finishedAt = $now;
            $run->durationMs = self::milliseconds($message->duration) ?? (int) \round(($now - $run->startedAt) * 1000.0);
            $run->timeoutAt = null;
            $message->environment === '' or $run->environment = $message->environment;
            $message->traceId === '' or $run->traceId = $message->traceId;
            $message->output === '' or $run->output = $message->output;
        }

        $monitor->lastCheckInAt = $now;
        $this->expect($monitor, $now);
        $message->output === '' or $this->trimOutput();

        return $run->id;
    }

    /**
     * Times out the hung runs and marks the slots without a check-in. A monitor whose schedule fails stops
     * expecting slots; the other monitors are checked still.
     */
    public function check(float $now): void
    {
        if ($this->lastCheck !== null && $now - $this->lastCheck > self::DOWNTIME_GAP) {
            $this->downtimes[] = [$this->lastCheck, $now];
            $this->downtimes = \array_slice($this->downtimes, -self::MAX_DOWNTIMES);
        }
        $this->lastCheck = $now;

        $changed = [];
        foreach ($this->monitors as $monitor) {
            try {
                $timedOut = $this->timeOut($monitor, $now);
                $missed = $this->miss($monitor, $now);
            } catch (\Throwable) {
                $monitor->nextExpectedAt = null;
                $timedOut = $missed = true;
            }
            $timedOut || $missed and $changed[$monitor->project] = true;
        }

        foreach (\array_keys($changed) as $project) {
            ($this->changed)((string) $project);
        }
    }

    /**
     * @param string|null $project Null for every project.
     * @return list<Monitor> By project, then slug.
     */
    public function monitors(?string $project = null): array
    {
        $monitors = \array_values(\array_filter(
            $this->monitors,
            static fn(Monitor $monitor): bool => $project === null || $monitor->project === $project,
        ));
        \usort($monitors, static fn(Monitor $a, Monitor $b): int => [$a->project, $a->slug] <=> [$b->project, $b->slug]);

        return $monitors;
    }

    public function monitor(string $project, string $slug): ?Monitor
    {
        return $this->monitors[self::key($project, $slug)] ?? null;
    }

    /**
     * Deletes the monitor and its runs; the next check-in makes it again.
     */
    public function delete(string $project, string $slug): bool
    {
        $monitor = $this->monitor($project, $slug);
        if ($monitor === null) {
            return false;
        }

        unset($this->monitors[self::key($project, $slug)]);
        ($this->changed)($monitor->project);

        return true;
    }

    /**
     * Deletes the runs. The monitors stay, with their schedules, and keep expecting their slots.
     *
     * @param string|null $project Null for every project.
     */
    public function clear(?string $project = null): void
    {
        foreach ($this->monitors as $monitor) {
            if ($project === null || $monitor->project === $project) {
                $monitor->checkIns = [];
                $monitor->lastCheckInAt = null;
            }
        }
    }

    /**
     * @return non-empty-string
     */
    private static function key(string $project, string $slug): string
    {
        return "$project\n$slug";
    }

    /**
     * @return int<0, max>|null
     */
    private static function milliseconds(?float $seconds): ?int
    {
        return $seconds === null || !\is_finite($seconds) || $seconds < 0.0
            ? null
            : \max(0, (int) \round(\min($seconds, 366.0 * 86400.0) * 1000.0));
    }

    /**
     * @return non-empty-string
     */
    private static function id(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    /**
     * Expects the first slot after the start of the newest run: an older run that ends late does not move it
     * back. A crontab slot less than {@see self::EARLY} seconds, the margin and a quarter of the period after the
     * start is the slot of the run. A slot that
     * passed while the run went on is not expected: the next one after now is.
     */
    private function expect(Monitor $monitor, float $now): void
    {
        $start = null;
        foreach ($monitor->checkIns as $run) {
            $run->serverMade() or $start = \max($start ?? $run->startedAt, $run->startedAt);
        }

        try {
            if ($monitor->schedule === null || $start === null) {
                $monitor->nextExpectedAt = null;
                return;
            }

            $monitor->anchor = $start;
            $next = $monitor->slotAfter($start);
            if ($next !== null && $monitor->schedule->type === Schedule::CRONTAB) {
                $following = $monitor->slotAfter((float) $next);
                // A quarter of the period at most: a run is late more often than its host's clock is ahead
                $early = \min(self::EARLY, (float) ($monitor->margin * 60), $following === null ? \INF : ($following - $next) / 4);
                (float) $next - $start < $early and $next = $following;
            }
            $next !== null && $next <= $now and $next = $monitor->slotAfter($now);
            $monitor->nextExpectedAt = $next;
        } catch (\Throwable) {
            $monitor->nextExpectedAt = null;
        }
    }

    private function create(CheckInMessage $message): Monitor
    {
        if (\count($this->monitors) >= self::MAX_MONITORS) {
            $stale = null;
            foreach ($this->monitors as $monitor) {
                $active = $monitor->lastCheckInAt ?? $monitor->createdAt;
                $stale === null || $active < ($stale->lastCheckInAt ?? $stale->createdAt) and $stale = $monitor;
            }
            $stale === null or $this->delete($stale->project, $stale->slug);
        }

        return $this->monitors[self::key($message->project, $message->slug)]
            = new Monitor($message->project, $message->slug, $message->source, $message->time);
    }

    /**
     * The run a check-in is about: by its ID, or, for a ping without one that ends a run, the newest ping run
     * in progress. Null for a new run.
     */
    private function runOf(Monitor $monitor, CheckInMessage $message): ?CheckIn
    {
        if ($message->checkInId !== '') {
            $run = $monitor->checkIns[$message->checkInId] ?? null;
            return $run?->serverMade() ? null : $run;
        }

        if ($message->source !== CheckInMessage::SOURCE_PING || $message->status === CheckInMessage::IN_PROGRESS) {
            return null;
        }

        foreach ($monitor->history() as $run) {
            if ($run->status === CheckInMessage::IN_PROGRESS && $run->source === CheckInMessage::SOURCE_PING) {
                return $run;
            }
        }

        return null;
    }

    private function open(Monitor $monitor, CheckInMessage $message): CheckIn
    {
        $now = $message->time;
        $running = $message->status === CheckInMessage::IN_PROGRESS;
        $duration = $running ? null : self::milliseconds($message->duration);
        $id = $message->checkInId === '' || isset($monitor->checkIns[$message->checkInId])
            ? self::id()
            : $message->checkInId;

        return $this->add($monitor, new CheckIn(
            id: $id,
            status: $message->status,
            startedAt: $duration === null ? $now : $now - (float) $duration / 1000.0,
            seq: ++$this->seq,
            source: $message->source,
            finishedAt: $running ? null : $now,
            durationMs: $duration,
            environment: $message->environment,
            traceId: $message->traceId,
            output: $message->output,
            timeoutAt: $running ? $now + (float) ($monitor->maxRuntime * 60) : null,
            maxRuntime: $monitor->maxRuntime,
        ));
    }

    private function add(Monitor $monitor, CheckIn $run): CheckIn
    {
        $monitor->checkIns[$run->id] = $run;
        if (\count($monitor->checkIns) > self::MAX_CHECK_INS) {
            foreach (\array_slice($monitor->history(), self::MAX_CHECK_INS) as $old) {
                unset($monitor->checkIns[$old->id]);
            }
        }

        return $run;
    }

    /**
     * Keeps the output of the newest runs within {@see self::MAX_OUTPUT_BYTES}.
     */
    private function trimOutput(): void
    {
        $runs = [];
        $bytes = 0;
        foreach ($this->monitors as $monitor) {
            foreach ($monitor->checkIns as $run) {
                if ($run->output !== '') {
                    $runs[] = $run;
                    $bytes += \strlen($run->output);
                }
            }
        }
        if ($bytes <= self::MAX_OUTPUT_BYTES) {
            return;
        }

        \usort($runs, static fn(CheckIn $a, CheckIn $b): int => [$a->startedAt, $a->seq] <=> [$b->startedAt, $b->seq]);
        foreach ($runs as $run) {
            $bytes -= \strlen($run->output);
            $run->output = '';
            if ($bytes <= self::MAX_OUTPUT_BYTES) {
                return;
            }
        }
    }

    /**
     * @return bool Whether a run timed out.
     */
    private function timeOut(Monitor $monitor, float $now): bool
    {
        $changed = false;
        foreach ($monitor->checkIns as $run) {
            if ($run->status === CheckInMessage::IN_PROGRESS && $run->timeoutAt !== null && $now > $run->timeoutAt) {
                $run->status = CheckIn::TIMEOUT;
                $changed = true;
            }
        }

        // The slots the hung run sat on are its fault, not missed runs
        $changed && $monitor->nextExpectedAt !== null && $monitor->nextExpectedAt <= $now
            and $monitor->nextExpectedAt = $monitor->slotAfter($now);

        return $changed;
    }

    /**
     * Marks the slots whose margin has passed. A slot that fell in a downtime makes one `unknown` row for all
     * the slots of that downtime, and the next slot expected is the first after it.
     *
     * @return bool Whether a slot was marked.
     */
    private function miss(Monitor $monitor, float $now): bool
    {
        if ($monitor->running()) {
            return false;
        }

        $margin = (float) ($monitor->margin * 60);
        $marked = 0;
        $changed = false;
        while ($monitor->nextExpectedAt !== null && $now > $monitor->nextExpectedAt + $margin) {
            $slot = $monitor->nextExpectedAt;
            $changed = true;
            $downtime = $this->downtimeOf((float) $slot, $slot + $margin);
            if ($downtime !== null) {
                $downtime[0] === $monitor->unknownSince or $this->mark($monitor, CheckIn::UNKNOWN, $slot);
                $monitor->unknownSince = $downtime[0];
                $next = $monitor->slotAfter(\max((float) $slot, $downtime[1] - 1.0));
            } elseif ($marked >= self::MAX_MISSED) {
                $next = $monitor->slotAfter($now - $margin);
            } else {
                $this->mark($monitor, CheckIn::MISSED, $slot);
                ++$marked;
                $next = $monitor->slotAfter((float) $slot);
            }

            // A schedule that does not move on is expected no more, rather than looped on
            $monitor->nextExpectedAt = $next !== null && $next > $slot ? $next : null;
        }

        return $changed;
    }

    private function mark(Monitor $monitor, string $status, int $slot): void
    {
        $this->add($monitor, new CheckIn(
            id: self::id(),
            status: $status,
            startedAt: (float) $slot,
            seq: ++$this->seq,
            source: $monitor->source,
            expectedAt: (float) $slot,
        ));
    }

    /**
     * @return array{float, float}|null The downtime the span overlaps.
     */
    private function downtimeOf(float $from, float $to): ?array
    {
        foreach ($this->downtimes as $downtime) {
            if ($from < $downtime[1] && $to > $downtime[0]) {
                return $downtime;
            }
        }

        return null;
    }
}
