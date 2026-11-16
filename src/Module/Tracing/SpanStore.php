<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing;

use Buggregator\Trap\Module\Tracing\Config\Traces as Config;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Module\Tracing\Frame\Trace\StatusCode;

/**
 * Spans received by the Trap process, kept so that spans of one trace that arrive in separate
 * export requests can be put together.
 *
 * Every stored span gets the next sequence number, and a trace keeps the one of its last span:
 * {@see traces()} lists the traces changed after a moment by it.
 *
 * Memory is bounded by {@see Config}: a trace is dropped when it has not received a span for `ttl` seconds,
 * and the least recently updated traces are dropped while the store holds more than `maxSpans` spans.
 * A single trace above `maxSpans` keeps only its most recently received spans, 90% of `maxSpans`.
 *
 * The container keeps one store for the Trap process, filled by the OTLP middleware while the MCP server runs
 * and read by the MCP tools. The console renderer keeps a store of its own, filled as it renders.
 *
 * @internal
 */
final class SpanStore
{
    /**
     * A random ID of the store, and so of the Trap process: sequence numbers of another process mean nothing here.
     * @var non-empty-string
     */
    public readonly string $id;

    /**
     * Ordered from the least to the most recently updated trace.
     *
     * @var array<non-empty-string, array<non-empty-string, Span>> Trace ID => span ID => span
     */
    private array $traces = [];

    /** @var array<non-empty-string, float> Trace ID => time of the last added span */
    private array $updatedAt = [];

    /**
     * Unique and growing in the order of {@see $traces}.
     *
     * @var array<non-empty-string, int<1, max>> Trace ID => sequence number of its last stored span
     */
    private array $changedAt = [];

    /** @var array<non-empty-string, array<non-empty-string, string>> Trace ID => span ID => service name */
    private array $services = [];

    /** @var int<0, max> */
    private int $seq = 0;

    /**
     * Children of each parent in the order of the trace, so that a trace is not rescanned
     * for every received span. The parent may be absent from the trace.
     *
     * @var array<non-empty-string, array<non-empty-string, array<non-empty-string, true>>>
     *      Trace ID => parent span ID => child span ID => true
     */
    private array $children = [];

    /**
     * @var array<non-empty-string, array{int, int}|null> Trace ID => earliest start and latest end
     *      of its spans; null when a replaced or dropped span may have defined them.
     */
    private array $bounds = [];

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
        $this->id = \bin2hex(\random_bytes(4));
    }

    /**
     * A span with an already stored ID replaces the stored one. The limits apply after all the spans.
     */
    public function add(Span ...$spans): void
    {
        $now = ($this->clock)();
        foreach ($spans as $span) {
            $traceId = $span->traceId;
            $trace = $this->traces[$traceId] ?? [];
            // Re-inserting moves the trace to the end, keeping the update order. Unset before `$trace` changes:
            // with one owner the array is changed in place, not copied.
            unset($this->traces[$traceId]);

            // Re-inserting a known span moves it to the end too, so the oldest spans stay first.
            $old = $trace[$span->spanId] ?? null;
            if ($old !== null) {
                unset($trace[$span->spanId], $this->services[$traceId][$span->spanId]);
                $this->unindex($old, $span);
            } else {
                ++$this->spanCount;
            }
            $trace[$span->spanId] = $span;
            $this->traces[$traceId] = $trace;
            $this->updatedAt[$traceId] = $now;
            $this->changedAt[$traceId] = ++$this->seq;
            $this->index($span, \count($trace) === 1);
        }

        $this->evict($now);
    }

    /**
     * Adds the spans of the frame with the service names of their resources. The limits apply after each span,
     * as for spans added one by one.
     */
    public function receive(OtlpTraces $frame): void
    {
        foreach ($frame->iterateSpans() as [$resource, , $span]) {
            $this->add($span);
            $service = $resource->getServiceName();
            // The limits never drop the span just added: it is the most recent one.
            $service === null or $this->services[$span->traceId][$span->spanId] = $service;
        }
    }

    /**
     * The sequence number of the last stored span; 0 before the first one.
     *
     * @return int<0, max>
     */
    public function cursor(): int
    {
        return $this->seq;
    }

    /**
     * Summaries of the traces changed after the cursor, from the least recently updated one: the
     * {@see TraceSummary::$seq} of a listed trace is a cursor for the traces after it.
     * Traces past their TTL are dropped first.
     *
     * @param int<0, max> $cursor A value of {@see cursor()} or {@see TraceSummary::$seq}; 0 for all the traces.
     *
     * @return \Generator<int, TraceSummary> Computed one by one: stop reading when enough.
     */
    public function traces(int $cursor = 0): \Generator
    {
        $this->evict(($this->clock)());

        foreach (\array_keys($this->traces) as $traceId) {
            // Cast back: PHP would turn an all-digit ID into an int key.
            $traceId = (string) $traceId;
            $this->changedAt[$traceId] > $cursor and yield $this->summarize($traceId);
        }
    }

    /**
     * @return TraceSummary|null Null for an unknown or expired trace.
     */
    public function summary(string $traceId): ?TraceSummary
    {
        $this->evict(($this->clock)());

        return $traceId === '' || !isset($this->traces[$traceId]) ? null : $this->summarize($traceId);
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
     * @return list<Span> Stored spans whose parent is the given span, in the order of arrival.
     */
    public function getChildren(string $traceId, string $spanId): array
    {
        $result = [];
        $trace = $this->traces[$traceId] ?? [];
        foreach ($this->children[$traceId][$spanId] ?? [] as $childId => $_) {
            $result[] = $trace[$childId];
        }

        return $result;
    }

    /**
     * @return array{int, int}|null The earliest start and the latest end of the spans of the trace,
     *         in Unix nanoseconds; null for an unknown trace.
     */
    public function getTraceBounds(string $traceId): ?array
    {
        $trace = $this->traces[$traceId] ?? [];
        // An empty ID is never stored; the check tells Psalm the key is a non-empty string.
        if ($trace === [] || $traceId === '') {
            return null;
        }

        // Bounds dropped by a replaced span are recomputed here once and cached again.
        return $this->bounds[$traceId] ??= self::computeBounds($trace);
    }

    /**
     * @return int<0, max>
     */
    public function countTraceSpans(string $traceId): int
    {
        return \count($this->traces[$traceId] ?? []);
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
     * @param array<array-key, Span> $trace Not empty.
     * @return array{int, int}
     */
    private static function computeBounds(array $trace): array
    {
        $start = \PHP_INT_MAX;
        $end = \PHP_INT_MIN;
        foreach ($trace as $span) {
            $start = \min($start, $span->startTimeUnixNano);
            $end = \max($end, $span->endTimeUnixNano);
        }

        return [$start, $end];
    }

    /**
     * @param non-empty-string $traceId A stored trace.
     */
    private function summarize(string $traceId): TraceSummary
    {
        $trace = $this->traces[$traceId];
        \assert($trace !== []);
        $root = null;
        $error = false;
        foreach ($trace as $span) {
            $error = $error || $span->status->code === StatusCode::Error;
            $parentId = $span->parentSpanId;
            if ($parentId !== null && ($parentId === $span->spanId || isset($trace[$parentId]))) {
                continue;
            }

            // A span without a parent wins over one whose parent has not arrived, then the earliest one.
            $rank = [$parentId === null ? 0 : 1, $span->startTimeUnixNano];
            if ($root === null || $rank < $root[0]) {
                $root = [$rank, $span];
            }
        }

        $services = $this->services[$traceId] ?? [];
        $root = $root[1] ?? null;
        $service = $root === null ? null : $services[$root->spanId] ?? null;
        $service ??= $services === [] ? null : \reset($services);
        [$start, $end] = $this->getTraceBounds($traceId) ?? [0, 0];

        return new TraceSummary(
            traceId: $traceId,
            name: $root?->name,
            service: $service,
            spans: \count($trace),
            start: $start,
            end: $end,
            error: $error,
            updatedAt: $this->updatedAt[$traceId],
            seq: $this->changedAt[$traceId],
        );
    }

    /**
     * When the most recently updated trace alone exceeds the span limit, its oldest spans are dropped.
     */
    private function evict(float $now): void
    {
        $deadline = $now - $this->ttl;
        $spanCount = $this->spanCount;
        $traceCount = \count($this->traces);
        $dropped = [];
        // Unset after the loop: unsetting inside foreach copies the whole array on every call, and
        // array_key_first() in a loop rescans the holes left by earlier unsets.
        foreach ($this->traces as $traceId => $trace) {
            $expired = $this->updatedAt[$traceId] < $deadline;
            $overflow = $spanCount > $this->maxSpans && $traceCount > 1;
            if (!$expired && !$overflow) {
                break;
            }

            $dropped[] = $traceId;
            $spanCount -= \count($trace);
            --$traceCount;
        }

        foreach ($dropped as $traceId) {
            unset(
                $this->traces[$traceId],
                $this->updatedAt[$traceId],
                $this->changedAt[$traceId],
                $this->services[$traceId],
                $this->children[$traceId],
                $this->bounds[$traceId],
            );
        }
        $this->spanCount = \max(0, $spanCount);

        $traceId = \array_key_last($this->traces);
        if ($traceId === null || $this->spanCount <= $this->maxSpans) {
            return;
        }

        // Trimmed to 90% at once: dropping one span per call leaves holes at the start of the array
        // that every later trim has to skip, while array_slice() builds a compact one.
        // The index is rebuilt for the same reason, once per 10% of the limit.
        $keep = $this->maxSpans - \intdiv($this->maxSpans, 10);
        $trace = \array_slice($this->traces[$traceId], -$keep, null, true);
        $this->traces[$traceId] = $trace;
        $this->spanCount = \count($trace);
        $this->services[$traceId] = \array_intersect_key($this->services[$traceId] ?? [], $trace);

        $children = [];
        foreach ($trace as $span) {
            $span->parentSpanId === null or $children[$span->parentSpanId][$span->spanId] = true;
        }
        $this->children[$traceId] = $children;
        $this->bounds[$traceId] = self::computeBounds($trace);
    }

    /**
     * Adds a span that has just been appended to its trace.
     *
     * @param bool $only Whether it is the only span of the trace.
     */
    private function index(Span $span, bool $only): void
    {
        $span->parentSpanId === null
            or $this->children[$span->traceId][$span->parentSpanId][$span->spanId] = true;

        $cached = $this->bounds[$span->traceId] ?? null;
        $this->bounds[$span->traceId] = match (true) {
            $only => [$span->startTimeUnixNano, $span->endTimeUnixNano],
            $cached === null => null,
            default => [\min($cached[0], $span->startTimeUnixNano), \max($cached[1], $span->endTimeUnixNano)],
        };
    }

    /**
     * Removes a span that is being replaced by `$new`.
     */
    private function unindex(Span $old, Span $new): void
    {
        $parentId = $old->parentSpanId;
        if ($parentId !== null) {
            unset($this->children[$old->traceId][$parentId][$old->spanId]);
            if (($this->children[$old->traceId][$parentId] ?? null) === []) {
                unset($this->children[$old->traceId][$parentId]);
            }
        }

        // The bounds stay valid when the old span did not define them or the new one covers its time;
        // otherwise they are recomputed on the next read.
        $bounds = $this->bounds[$old->traceId] ?? null;
        $defined = $bounds !== null
            && ($old->startTimeUnixNano <= $bounds[0] || $old->endTimeUnixNano >= $bounds[1]);
        $covered = $new->startTimeUnixNano <= $old->startTimeUnixNano
            && $new->endTimeUnixNano >= $old->endTimeUnixNano;
        $defined && !$covered and $this->bounds[$old->traceId] = null;
    }
}
