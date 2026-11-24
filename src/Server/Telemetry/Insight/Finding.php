<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Insight;

use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * A problem a {@see TraceRule} found in a trace: the spans that show it and what they cost.
 *
 * @internal
 */
final class Finding
{
    /**
     * @param non-empty-string $rule The name of the rule, such as `n_plus_one`.
     * @param non-empty-string|null $transactionId The {@see Transaction::$id} the spans belong to; null when
     *        their transaction has not arrived.
     * @param non-empty-list<non-empty-string> $spanIds In the order of arrival.
     * @param non-empty-string $summary What the spans have in common, such as the shape of the repeated query.
     * @param int<1, max> $count
     * @param float $totalMs The summed durations of the spans, to a microsecond.
     * @param non-empty-string|null $origin The place in the code, as `file:line`, when a span tells it.
     */
    public function __construct(
        public readonly string $rule,
        public readonly ?string $transactionId,
        public readonly array $spanIds,
        public readonly string $summary,
        public readonly int $count,
        public readonly float $totalMs,
        public readonly ?string $origin = null,
    ) {}
}
