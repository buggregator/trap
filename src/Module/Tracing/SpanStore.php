<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing;

use Buggregator\Trap\Config\Server\Traces as Config;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;

/**
 * Spans received by the Trap process, kept so that spans of one trace that arrive in separate
 * export requests can be put together.
 *
 * Memory is bounded by {@see Config}: a trace is dropped when it has not received a span for `ttl` seconds,
 * and the least recently updated traces are dropped while the store holds more than `maxSpans` spans.
 * A single trace above `maxSpans` keeps only its most recently received spans, 90% of `maxSpans`.
 *
 * @internal
 */
final class SpanStore
{
    /**
     * Ordered from the least to the most recently updated trace.
     *
     * @var array<non-empty-string, array<non-empty-string, Span>> Trace ID => span ID => span
     */
    private array $traces = [];

    /** @var array<non-empty-string, float> Trace ID => time of the last added span */
    private array $updatedAt = [];

    /** @var int<0, max> */
    private int $spanCount = 0;

    /** @var int<1, max> */
    private readonly int $maxSpans;

    /** Seconds */
    private readonly float $ttl;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param \Closure(): float|null $clock Current time in seconds.
     */
    public function __construct(
        Config $config = new Config(),
        ?\Closure $clock = null,
    ) {
        // Config values come from ENV or XML as is; an invalid limit falls back to its default.
        $defaults = new Config();
        $maxSpans = $config->maxSpans >= 1 ? $config->maxSpans : $defaults->maxSpans;
        \assert($maxSpans >= 1);
        $this->maxSpans = $maxSpans;
        $this->ttl = $config->ttl > 0 && \is_finite($config->ttl) ? $config->ttl : $defaults->ttl;
        $this->clock = $clock ?? static fn(): float => \microtime(true);
    }

    /**
     * A span with an already stored ID replaces the stored one.
     */
    public function add(Span ...$spans): void
    {
        $now = ($this->clock)();
        foreach ($spans as $span) {
            $trace = $this->traces[$span->traceId] ?? [];
            // Re-inserting moves the trace to the end, keeping the update order.
            unset($this->traces[$span->traceId]);

            // Re-inserting a known span moves it to the end too, so the oldest spans stay first.
            if (isset($trace[$span->spanId])) {
                unset($trace[$span->spanId]);
            } else {
                ++$this->spanCount;
            }
            $trace[$span->spanId] = $span;
            $this->traces[$span->traceId] = $trace;
            $this->updatedAt[$span->traceId] = $now;
        }

        $this->evict($now);
    }

    public function get(string $traceId, string $spanId): ?Span
    {
        return $this->traces[$traceId][$spanId] ?? null;
    }

    /**
     * @return array<non-empty-string, Span> Span ID => span, in the order of arrival.
     */
    public function getTrace(string $traceId): array
    {
        return $this->traces[$traceId] ?? [];
    }

    /**
     * @return int<0, max>
     */
    public function countSpans(): int
    {
        return $this->spanCount;
    }

    /**
     * @return int<0, max>
     */
    public function countTraces(): int
    {
        return \count($this->traces);
    }

    /**
     * When the most recently updated trace alone exceeds the span limit, its oldest spans are dropped.
     */
    private function evict(float $now): void
    {
        $deadline = $now - $this->ttl;
        foreach ($this->traces as $traceId => $trace) {
            $expired = $this->updatedAt[$traceId] < $deadline;
            $overflow = $this->spanCount > $this->maxSpans && \count($this->traces) > 1;
            if (!$expired && !$overflow) {
                break;
            }

            unset($this->traces[$traceId], $this->updatedAt[$traceId]);
            $this->spanCount = \max(0, $this->spanCount - \count($trace));
        }

        $traceId = \array_key_last($this->traces);
        if ($traceId === null || $this->spanCount <= $this->maxSpans) {
            return;
        }

        // Trimmed to 90% at once: dropping one span per call leaves holes at the start of the array
        // that every later trim has to skip, while array_slice() builds a compact one.
        $keep = $this->maxSpans - \intdiv($this->maxSpans, 10);
        $this->traces[$traceId] = \array_slice($this->traces[$traceId], -$keep, null, true);
        $this->spanCount = \count($this->traces[$traceId]);
    }
}
