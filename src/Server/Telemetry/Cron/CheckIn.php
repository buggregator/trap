<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Cron;

/**
 * One run of a monitor, or a slot Trap marked: missed, or unknown when it fell while Trap was not running.
 *
 * Changed only by {@see Crons}.
 *
 * @internal
 */
final class CheckIn
{
    public const MISSED = 'missed';
    public const TIMEOUT = 'timeout';
    public const UNKNOWN = 'unknown';

    /**
     * @param non-empty-string $id
     * @param string $status A status of {@see CheckInMessage::STATUSES}, or one Trap writes.
     * @param float $startedAt Unix seconds; the slot of a row Trap made.
     * @param int<0, max> $seq The order of arrival in the store, for runs started at the same time.
     * @param float|null $timeoutAt When a run in progress times out.
     * @param float|null $expectedAt The slot of a row Trap made.
     * @param int $maxRuntime Minutes, as the monitor had them when the run started.
     */
    public function __construct(
        public readonly string $id,
        public string $status,
        public float $startedAt,
        public readonly int $seq,
        public readonly string $source,
        public ?float $finishedAt = null,
        public ?int $durationMs = null,
        public string $environment = '',
        public string $traceId = '',
        public string $output = '',
        public ?float $timeoutAt = null,
        public readonly ?float $expectedAt = null,
        public readonly int $maxRuntime = MonitorConfig::DEFAULT_MAX_RUNTIME,
    ) {}

    public function serverMade(): bool
    {
        return $this->expectedAt !== null;
    }
}
