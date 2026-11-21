<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

use Buggregator\Trap\Server\Config\Telemetry as Config;

/**
 * Traces, logs and metrics of Sentry SDKs and OTLP exporters, the data of the Sentry pages of the web UI.
 * Unlike events, they make no feed cards.
 *
 * A container singleton, filled by the event mappers of the Sentry and Tracing modules, so only while
 * an event consumer runs: the web UI or the MCP server.
 *
 * Memory is bounded by {@see Config}. Above `maxSpans` the least recently updated traces are dropped, and
 * a single trace above it keeps its most recent 90%. Above `maxLogs` or `maxMetrics` the oldest 10% go.
 *
 * @internal
 */
final class TelemetryStore
{
    /**
     * From the least to the most recently updated trace.
     *
     * @var array<array-key, Trace>
     */
    private array $traces = [];

    /** @var int<0, max> Transactions and spans of all the traces */
    private int $size = 0;

    /** @var list<Log> From the oldest */
    private array $logs = [];

    /** @var list<Metric> From the oldest */
    private array $metrics = [];

    /** @var list<TelemetryListener> */
    private array $listeners = [];

    /** @var int<1, max> */
    private readonly int $maxSpans;

    /** @var int<1, max> */
    private readonly int $maxLogs;

    /** @var int<1, max> */
    private readonly int $maxMetrics;

    public function __construct(Config $config = new Config())
    {
        $defaults = new Config();
        $this->maxSpans = \max(1, $config->maxSpans >= 1 ? $config->maxSpans : $defaults->maxSpans);
        $this->maxLogs = \max(1, $config->maxLogs >= 1 ? $config->maxLogs : $defaults->maxLogs);
        $this->maxMetrics = \max(1, $config->maxMetrics >= 1 ? $config->maxMetrics : $defaults->maxMetrics);
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
        foreach ($batch->transactions as $transaction) {
            $trace = $this->touch($transaction->traceId);
            isset($trace->transactions[$transaction->id]) or ++$this->size;
            $trace->transactions[$transaction->id] = $transaction;
        }

        foreach ($batch->spans as $span) {
            $trace = $this->touch($span->traceId);
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

        $kinds = $batch->kinds();
        if ($kinds !== []) {
            foreach ($this->listeners as $listener) {
                $listener->stored($batch->project, $kinds);
            }
        }
    }

    /**
     * @return array<array-key, Trace> From the least to the most recently updated one.
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
     * @param string|null $project Only the data of this project; everything when null.
     */
    public function clear(?string $project = null): void
    {
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
    private function touch(string $traceId): Trace
    {
        $trace = $this->traces[$traceId] ?? new Trace($traceId);
        unset($this->traces[$traceId]);

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
