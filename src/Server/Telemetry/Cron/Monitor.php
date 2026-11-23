<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Cron;

/**
 * A cron job of a project, made by its first check-in.
 *
 * Changed only by {@see Crons}.
 *
 * @internal
 */
final class Monitor
{
    /** @var array<non-empty-string, CheckIn> By ID, in the order of arrival */
    public array $checkIns = [];

    /** @var float|null Unix seconds of the last check-in the job sent */
    public ?float $lastCheckInAt = null;

    /** @var int|null Unix seconds of the next slot without a check-in yet; null without a schedule */
    public ?int $nextExpectedAt = null;

    /** @var float|null Unix seconds of the start of the newest run: the slots of an interval count from it */
    public ?float $anchor = null;

    /** @var float|null The start of the downtime the last `unknown` row stands for */
    public ?float $unknownSince = null;

    public ?Schedule $schedule = null;

    /** @var non-empty-string */
    public string $timezone = 'UTC';

    /** @var int<1, max> Minutes */
    public int $margin = MonitorConfig::DEFAULT_MARGIN;

    /** @var int<1, max> Minutes */
    public int $maxRuntime = MonitorConfig::DEFAULT_MAX_RUNTIME;

    /**
     * @param non-empty-string $project
     * @param non-empty-string $slug
     * @param CheckInMessage::SOURCE_* $source How the job sent its first check-in.
     * @param float $createdAt Unix seconds.
     */
    public function __construct(
        public readonly string $project,
        public readonly string $slug,
        public readonly string $source,
        public readonly float $createdAt,
    ) {}

    /**
     * @return list<CheckIn> The newest first: by start, then by arrival.
     */
    public function history(): array
    {
        $runs = \array_values($this->checkIns);
        \usort($runs, static fn(CheckIn $a, CheckIn $b): int => [$b->startedAt, $b->seq] <=> [$a->startedAt, $a->seq]);

        return $runs;
    }

    /**
     * The status of the newest run; empty before the first one.
     */
    public function lastStatus(): string
    {
        return $this->history()[0]->status ?? '';
    }

    public function running(): bool
    {
        foreach ($this->checkIns as $checkIn) {
            if ($checkIn->status === CheckInMessage::IN_PROGRESS) {
                return true;
            }
        }

        return false;
    }

    public function zone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone);
    }

    /**
     * The first slot after `$time`, an interval counted from {@see self::$anchor}; null without a schedule.
     */
    public function slotAfter(float $time): ?int
    {
        return $this->schedule?->after($this->anchor ?? $time, $time, $this->zone());
    }

    /**
     * Applies a config: a field it leaves out keeps its value.
     */
    public function configure(MonitorConfig $config): void
    {
        $config->schedule === null or $this->schedule = $config->schedule;
        $config->timezone === null or $this->timezone = $config->timezone;
        $config->margin === null or $this->margin = $config->margin;
        $config->maxRuntime === null or $this->maxRuntime = $config->maxRuntime;
    }
}
