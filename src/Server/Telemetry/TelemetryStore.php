<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

use Buggregator\Trap\Server\Config\Telemetry as Config;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Processable;
use Buggregator\Trap\Server\Telemetry\Cron\Crons;

/**
 * Traces, logs and metrics of Sentry SDKs and OTLP exporters, Sentry user feedback and cron monitors: the data of
 * the Sentry pages of the web UI and of the trace, feedback and cron tools of the MCP server. Unlike events, they
 * make no feed cards.
 *
 * A container singleton, filled by the event mappers of the Sentry and Tracing modules, so only while
 * an event consumer runs: the web UI or the MCP server, its only readers.
 *
 * Every change of a trace gets the next sequence number: a trace keeps the one of its last change,
 * and {@see traces()} lists the traces in the order of these numbers.
 *
 * Memory is bounded by {@see Config}. Above `maxSpans` the least recently updated traces are dropped, and
 * a single trace above it keeps its most recent 90%. Above `maxLogs` or `maxMetrics` the oldest 10% go,
 * and so above {@see self::MAX_FEEDBACK} for feedback. {@see Crons} bounds the cron monitors.
 *
 * The main loop runs {@see process()} while an event consumer runs: about once a second it marks the cron runs
 * that did not come or hung.
 *
 * @internal
 */
final class TelemetryStore implements Processable
{
    public const MAX_FEEDBACK = 1000;

    /** Seconds between two checks of the cron monitors */
    public const CRON_CHECK_INTERVAL = 1.0;

    public readonly Crons $crons;

    /**
     * A random ID of the store, and so of the Trap process: sequence numbers of another process mean nothing here.
     * @var non-empty-string
     */
    public readonly string $id;

    /**
     * From the least to the most recently updated trace.
     *
     * @var array<array-key, Trace>
     */
    private array $traces = [];

    /** @var int<0, max> Transactions and spans of all the traces */
    private int $size = 0;

    /** @var int<0, max> */
    private int $seq = 0;

    /** @var list<Log> From the oldest */
    private array $logs = [];

    /** @var list<Metric> From the oldest */
    private array $metrics = [];

    /** @var list<Feedback> From the oldest */
    private array $feedback = [];

    /** @var list<TelemetryListener> */
    private array $listeners = [];

    /** @var int<1, max> */
    private readonly int $maxSpans;

    /** @var int<1, max> */
    private readonly int $maxLogs;

    /** @var int<1, max> */
    private readonly int $maxMetrics;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private ?float $cronCheckedAt = null;

    /**
     * @param \Closure(): float|null $clock Current Unix time in seconds.
     * @param Logger|null $logger Told what failed in the checks of the cron monitors.
     */
    public function __construct(
        Config $config = new Config(),
        ?\Closure $clock = null,
        private readonly ?Logger $logger = null,
    ) {
        $defaults = new Config();
        $this->maxSpans = \max(1, $config->maxSpans >= 1 ? $config->maxSpans : $defaults->maxSpans);
        $this->maxLogs = \max(1, $config->maxLogs >= 1 ? $config->maxLogs : $defaults->maxLogs);
        $this->maxMetrics = \max(1, $config->maxMetrics >= 1 ? $config->maxMetrics : $defaults->maxMetrics);
        $this->clock = $clock ?? static fn(): float => \microtime(true);
        $this->id = \bin2hex(\random_bytes(4));
        $this->crons = new Crons(function (string $project): void {
            // Each listener is told, whatever another one throws
            foreach ($this->listeners as $listener) {
                try {
                    $listener->stored($project, ['cron']);
                } catch (\Throwable $e) {
                    $this->logger?->exception($e, 'Sentry Crons: a listener failed', important: true);
                }
            }
        }, $logger);
    }

    #[\Override]
    public function process(): void
    {
        $now = ($this->clock)();
        // Both ways: a clock set back must not stop the checks
        if ($this->cronCheckedAt !== null && \abs($now - $this->cronCheckedAt) < self::CRON_CHECK_INTERVAL) {
            return;
        }

        $this->cronCheckedAt = $now;
        try {
            $this->crons->check($now);
        } catch (\Throwable $e) {
            // The main loop runs it: a failure must not stop Trap
            $this->logger?->exception($e, 'Sentry Crons: the check failed', important: true);
        }
    }

    public function listen(TelemetryListener $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * A transaction or a span with a stored ID in its trace replaces the stored one.
     * Listeners are told the kinds the batch held, unless it was empty.
     */
    public function add(Batch $batch): void
    {
        $now = ($this->clock)();
        foreach ($batch->transactions as $transaction) {
            $trace = $this->touch($transaction->traceId, $now);
            isset($trace->transactions[$transaction->id]) or ++$this->size;
            $trace->transactions[$transaction->id] = $transaction;
        }

        foreach ($batch->spans as $span) {
            $trace = $this->touch($span->traceId, $now);
            isset($trace->spans[$span->spanId]) or ++$this->size;
            unset($trace->spans[$span->spanId]);
            $trace->spans[$span->spanId] = $span;
        }

        $this->evictTraces();

        if ($batch->logs !== []) {
            $this->logs = self::trim([...$this->logs, ...$batch->logs], $this->maxLogs);
        }

        if ($batch->metrics !== []) {
            $this->metrics = self::trim([...$this->metrics, ...$batch->metrics], $this->maxMetrics);
        }

        if ($batch->feedback !== []) {
            $this->feedback = self::trim([...$this->feedback, ...$batch->feedback], self::MAX_FEEDBACK);
        }

        foreach ($batch->checkIns as $checkIn) {
            $this->crons->record($checkIn);
        }

        $kinds = $batch->kinds();
        if ($kinds !== []) {
            foreach ($this->listeners as $listener) {
                $listener->stored($batch->project, $kinds);
            }
        }
    }

    /**
     * The sequence number of the last change of a trace; 0 before the first one.
     *
     * @return int<0, max>
     */
    public function cursor(): int
    {
        return $this->seq;
    }

    /**
     * @return array<array-key, Trace> From the least to the most recently updated one, so in the order
     *         of {@see Trace::$seq}.
     */
    public function traces(): array
    {
        return $this->traces;
    }

    public function trace(string $traceId): ?Trace
    {
        return $this->traces[$traceId] ?? null;
    }

    /**
     * @return list<Log> From the oldest.
     */
    public function logs(): array
    {
        return $this->logs;
    }

    /**
     * @return list<Metric> From the oldest.
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * @return list<Feedback> From the oldest.
     */
    public function feedback(): array
    {
        return $this->feedback;
    }

    /**
     * @param string|null $project Only the data of this project; everything when null.
     * @param list<string> $keepFeedbackOn Sentry event IDs of errors whose feedback stays, as of pinned errors.
     */
    public function clear(?string $project = null, array $keepFeedbackOn = []): void
    {
        $this->crons->clear($project);
        $this->feedback = \array_values(\array_filter(
            $this->feedback,
            static function (Feedback $feedback) use ($project, $keepFeedbackOn): bool {
                if ($project !== null && $feedback->project !== $project) {
                    return true;
                }
                foreach ($keepFeedbackOn as $eventId) {
                    if ($feedback->isAbout($eventId)) {
                        return true;
                    }
                }
                return false;
            },
        ));

        if ($project === null) {
            $this->traces = $this->logs = $this->metrics = [];
            $this->size = 0;
            return;
        }

        $other = static fn(Log|Metric|Span|Transaction $item): bool => $item->project !== $project;
        $this->logs = \array_values(\array_filter($this->logs, $other));
        $this->metrics = \array_values(\array_filter($this->metrics, $other));

        $this->size = 0;
        foreach ($this->traces as $id => $trace) {
            $trace->transactions = \array_filter($trace->transactions, $other);
            $trace->spans = \array_filter($trace->spans, $other);
            $size = $trace->size();
            if ($size === 0) {
                unset($this->traces[$id]);
            }
            $this->size += $size;
        }
    }

    /**
     * @template T
     * @param list<T> $items
     * @param int<1, max> $max
     * @return list<T> The newest 90% of `$max` when there are more than `$max` items.
     */
    private static function trim(array $items, int $max): array
    {
        return \count($items) > $max ? \array_slice($items, -($max - \intdiv($max, 10))) : $items;
    }

    /**
     * Moves the trace to the end of the update order, creating it when needed.
     *
     * @param non-empty-string $traceId
     */
    private function touch(string $traceId, float $now): Trace
    {
        $trace = $this->traces[$traceId] ?? new Trace($traceId);
        unset($this->traces[$traceId]);
        $trace->seq = ++$this->seq;
        $trace->updatedAt = $now;

        return $this->traces[$traceId] = $trace;
    }

    private function evictTraces(): void
    {
        if ($this->size <= $this->maxSpans) {
            return;
        }

        $count = \count($this->traces);
        foreach ($this->traces as $id => $trace) {
            if ($this->size <= $this->maxSpans || $count === 1) {
                break;
            }

            $this->size = \max(0, $this->size - $trace->size());
            --$count;
            unset($this->traces[$id]);
        }

        $trace = \end($this->traces);
        if ($trace === false || $this->size <= $this->maxSpans) {
            return;
        }

        // A single trace above the limit keeps its most recent spans, then transactions.
        $keep = $this->maxSpans - \intdiv($this->maxSpans, 10);
        $trace->transactions = \array_slice($trace->transactions, -$keep, null, true);
        $trace->spans = \array_slice($trace->spans, -\max(0, $keep - \count($trace->transactions)), null, true);
        $keep === \count($trace->transactions) and $trace->spans = [];
        $this->size = $trace->size();
    }
}
