<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

use Buggregator\Trap\Server\Telemetry\Insight\Finding;
use Buggregator\Trap\Server\Telemetry\Insight\TraceInsights;

/**
 * The stored transactions and spans of one trace, of any project: a distributed trace may cross several.
 *
 * Changed only by the {@see TelemetryStore}, but for the {@see self::$insights} cache.
 *
 * @internal
 */
final class Trace
{
    /** @var array<non-empty-string, Transaction> By ID, in the order of arrival */
    public array $transactions = [];

    /** @var array<array-key, Span> By span ID, an int key for an all-digit one; in the order of arrival */
    public array $spans = [];

    /** @var int<0, max> The {@see TelemetryStore::cursor()} of its last change */
    public int $seq = 0;

    /** Unix seconds of its last change */
    public float $updatedAt = 0.0;

    /**
     * The cache of {@see TraceInsights}: the pipeline, the sequence number and the size the findings were found at,
     * and the findings. It goes with the trace.
     *
     * @var array{TraceInsights, int<0, max>, int<0, max>, list<Finding>}|null
     */
    public ?array $insights = null;

    /**
     * @param non-empty-string $id
     */
    public function __construct(
        public readonly string $id,
    ) {}

    /**
     * @return int<0, max> Transactions and spans.
     */
    public function size(): int
    {
        return \count($this->transactions) + \count($this->spans);
    }

    /**
     * The entry of the trace: the transaction without a parent span, else the earliest one.
     *
     * @param non-empty-string|null $project Only the transactions of this project.
     */
    public function root(?string $project = null): ?Transaction
    {
        $root = null;
        foreach ($this->transactions as $transaction) {
            if ($project !== null && $transaction->project !== $project) {
                continue;
            }

            if ($root === null || self::rank($transaction) < self::rank($root)) {
                $root = $transaction;
            }
        }

        return $root;
    }

    /**
     * @param non-empty-string|null $project
     * @return list<Span>
     */
    public function spans(?string $project = null): array
    {
        return \array_values($project === null
            ? $this->spans
            : \array_filter($this->spans, static fn(Span $span): bool => $span->project === $project));
    }

    /**
     * @param non-empty-string|null $project
     * @return list<Transaction>
     */
    public function transactions(?string $project = null): array
    {
        return \array_values($project === null
            ? $this->transactions
            : \array_filter($this->transactions, static fn(Transaction $t): bool => $t->project === $project));
    }

    /**
     * @return array{bool, float, string}
     */
    private static function rank(Transaction $transaction): array
    {
        return [$transaction->parentSpanId !== null, $transaction->start ?? \INF, $transaction->id];
    }
}
