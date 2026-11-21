<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend\Api;

use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Trace;
use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * Transactions and spans of a trace by their parent span, built once per trace: looking up the callee of
 * every call by scanning the trace takes seconds on a trace of thousands of spans.
 *
 * @internal
 */
final class TraceIndex
{
    /** @var array<string, list<Transaction>> Parent span ID => transactions, in the order of arrival */
    private array $transactionsByParent = [];

    /** @var array<string, list<Span>> Parent span ID => spans, in the order of arrival */
    private array $spansByParent = [];

    /** @var array<string, list<Span>> Transaction ID => its spans, in the order of arrival */
    private array $spansByTransaction = [];

    /**
     * @param non-empty-string|null $project Only the part of the trace sent to this project.
     */
    public function __construct(Trace $trace, ?string $project = null)
    {
        foreach ($trace->transactions($project) as $transaction) {
            $transaction->parentSpanId === null
                or $this->transactionsByParent[$transaction->parentSpanId][] = $transaction;
        }

        foreach ($trace->spans($project) as $span) {
            $span->parentSpanId === null or $this->spansByParent[$span->parentSpanId][] = $span;
            $span->transactionId === null or $this->spansByTransaction[$span->transactionId][] = $span;
        }
    }

    /**
     * The other service that served a call: by an entry transaction whose parent is the calling span,
     * else by such a span, else, with `$transactionSpans`, by a span of such a transaction.
     * Databases, caches and queues have none.
     *
     * @param string $service The service of the calling span; empty when unknown.
     */
    public function callee(Span $call, string $service, bool $transactionSpans = false): Transaction|Span|null
    {
        if (\in_array($call->peer->type, ['db', 'cache', 'queue'], true)) {
            return null;
        }

        $other = static fn(?string $name): bool => $name !== null && $name !== '' && $name !== $service;
        $entries = $this->transactionsByParent[$call->spanId] ?? [];
        foreach ($entries as $transaction) {
            if ($other($transaction->service)) {
                return $transaction;
            }
        }

        foreach ($this->spansByParent[$call->spanId] ?? [] as $child) {
            if ($other($child->service)) {
                return $child;
            }
        }

        if ($transactionSpans) {
            foreach ($entries as $transaction) {
                foreach ($this->spansByTransaction[$transaction->id] ?? [] as $child) {
                    if ($other($child->service)) {
                        return $child;
                    }
                }
            }
        }

        return null;
    }
}
