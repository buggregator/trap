<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Mcp;

use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Trace;
use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * A stored trace at a glance, or the part of it sent to some projects: a distributed trace may cross several.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Mcp
 */
final class TraceSummary
{
    /**
     * @param list<Transaction> $transactions
     * @param list<Span> $spans Without the spans of the IDs of the transactions.
     * @param string|null $name The name of the root transaction: the one without a parent, else the earliest.
     *        Without transactions, of the earliest span whose parent has not arrived; null when every span has
     *        a stored parent, as in a parent cycle.
     * @param string|null $service Of the root, else of any part.
     * @param list<non-empty-string> $services
     * @param list<'sentry'|'otel'> $sources
     * @param list<non-empty-string> $projects
     * @param float|null $start Unix seconds of the earliest start; null when no part has a start.
     * @param float|null $end Unix seconds of the latest end.
     */
    private function __construct(
        public readonly Trace $trace,
        public readonly array $transactions,
        public readonly array $spans,
        public readonly ?string $name,
        public readonly ?string $service,
        public readonly array $services,
        public readonly array $sources,
        public readonly array $projects,
        public readonly ?float $start,
        public readonly ?float $end,
        public readonly bool $error,
    ) {}

    /**
     * @param list<non-empty-string> $projects The parts of these projects; all of them when empty.
     * @return self|null Null when the trace has no part in the projects.
     */
    public static function of(Trace $trace, array $projects = []): ?self
    {
        $in = static fn(Span|Transaction $part): bool => $projects === [] || \in_array($part->project, $projects, true);
        $transactions = \array_values(\array_filter($trace->transactions, $in));
        $spans = \array_filter($trace->spans, $in);
        // A span and a transaction of one ID are one span, as on the trace page of the UI.
        foreach ($transactions as $transaction) {
            if ($transaction->spanId !== null) {
                unset($spans[$transaction->spanId]);
            }
        }
        $spans = \array_values($spans);
        if ($transactions === [] && $spans === []) {
            return null;
        }

        $root = self::rootTransaction($transactions) ?? self::rootSpan($spans);
        $parts = [...$transactions, ...$spans];
        $services = $sources = $projectList = [];
        $start = $end = null;
        $error = false;
        foreach ($parts as $part) {
            $part->service === null || $part->service === '' or $services[$part->service] = true;
            $sources[$part->source->value] = true;
            $projectList[$part->project] = true;
            $part->start === null or $start = \min($start ?? \INF, $part->start);
            $part->end === null or $end = \max($end ?? -\INF, $part->end);
            $error = $error || $part->isError();
        }

        /** @var list<non-empty-string> $services */
        $services = \array_keys($services);
        /** @var list<'sentry'|'otel'> $sources */
        $sources = \array_keys($sources);
        /** @var list<non-empty-string> $projectList */
        $projectList = \array_map('strval', \array_keys($projectList));

        return new self(
            trace: $trace,
            transactions: $transactions,
            spans: $spans,
            name: match (true) {
                $root instanceof Transaction => $root->name,
                $root instanceof Span => $root->description,
                default => null,
            },
            service: $root?->service === null || $root->service === '' ? $services[0] ?? null : $root->service,
            services: $services,
            sources: $sources,
            projects: $projectList,
            start: $start,
            end: $end,
            error: $error,
        );
    }

    /**
     * @return int<1, max> Transactions and spans.
     */
    public function count(): int
    {
        return \max(1, \count($this->transactions) + \count($this->spans));
    }

    /**
     * From the earliest start to the latest end, to a microsecond.
     */
    public function durationMs(): float
    {
        return $this->start === null || $this->end === null || $this->end <= $this->start
            ? 0.0
            : \round(($this->end - $this->start) * 1000.0, 3);
    }

    /**
     * @param list<Transaction> $transactions
     */
    private static function rootTransaction(array $transactions): ?Transaction
    {
        $root = null;
        foreach ($transactions as $transaction) {
            $rank = [$transaction->parentSpanId !== null, $transaction->start ?? \INF, $transaction->id];
            if ($root === null || $rank < $root[0]) {
                $root = [$rank, $transaction];
            }
        }

        return $root[1] ?? null;
    }

    /**
     * @param list<Span> $spans
     */
    private static function rootSpan(array $spans): ?Span
    {
        $ids = [];
        foreach ($spans as $span) {
            $ids[$span->spanId] = true;
        }

        $root = null;
        foreach ($spans as $span) {
            $parentId = $span->parentSpanId;
            if ($parentId !== null && ($parentId === $span->spanId || isset($ids[$parentId]))) {
                continue;
            }

            // A span without a parent wins over one whose parent has not arrived, then the earliest one.
            $rank = [$parentId === null ? 0 : 1, $span->start ?? \INF];
            if ($root === null || $rank < $root[0]) {
                $root = [$rank, $span];
            }
        }

        return $root[1] ?? null;
    }
}
