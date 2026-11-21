<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend\Api;

use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Trace;
use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * The calls of a trace in time order, as buggregator/server answers `traces/{id}/sequence`: who called whom,
 * how long the caller waited, and whether the callee kept working after the caller stopped waiting
 * (`late_ms` above 0).
 *
 * @internal
 */
final class Sequence
{
    /**
     * @param non-empty-string|null $project Only the part of the trace sent to this project.
     * @return array|null Null when the trace has nothing in the project.
     */
    public static function json(Trace $trace, ?string $project = null): ?array
    {
        $transactions = $trace->transactions($project);
        $spans = $trace->spans($project);
        if ($transactions === [] && $spans === []) {
            return null;
        }

        $serviceOf = [];
        foreach ($transactions as $transaction) {
            $serviceOf[$transaction->id] = $transaction->service ?? '';
        }

        /** @var array<string, float|null> $order Service => its first time */
        $order = [];
        $note = static function (string $service, ?float $time) use (&$order): void {
            if ($service === '') {
                return;
            }
            $known = $order[$service] ?? null;
            if (!\array_key_exists($service, $order) || ($time !== null && ($known === null || $time < $known))) {
                $order[$service] = $time;
            }
        };
        foreach ($transactions as $transaction) {
            $note($transaction->service ?? '', $transaction->start);
        }

        $calls = [];
        foreach ($spans as $span) {
            $service = $span->service ?? $serviceOf[(string) $span->transactionId] ?? '';
            $from = $service === '' ? 'unknown' : $service;

            [$callee, $calleeStart, $calleeEnd] = self::callee($span, $service, $transactions, $spans);
            if ($callee === null && $span->peer->address === null) {
                continue;
            }

            $to = $span->peer->address ?? '';
            if ($callee !== null) {
                $to = (string) $callee['service'];
                // A callee of spans only, without a transaction, needs its lane too.
                $note($to, $calleeStart);
            }

            $note($from, $span->start);
            $calls[] = [$span->start, [
                'span_id' => $span->spanId,
                'from' => $from,
                'to' => $to,
                'op_type' => $span->peer->type,
                'description' => $span->description,
                'start_ts' => Json::time($span->start),
                'end_ts' => Json::time($span->end),
                'duration_ms' => $span->durationMs(),
                'is_error' => $span->isError(),
                'status' => $span->status,
                'callee' => $callee,
                'late_ms' => $callee !== null && $calleeEnd !== null && $span->end !== null
                    ? (int) \round(($calleeEnd - $span->end) * 1000.0)
                    : null,
            ]];
        }

        \usort($calls, static fn(array $a, array $b): int => ($a[0] ?? 0.0) <=> ($b[0] ?? 0.0));
        $services = \array_map('strval', \array_keys($order));
        \usort($services, static fn(string $a, string $b): int => [$order[$a] ?? 0.0, $a] <=> [$order[$b] ?? 0.0, $b]);

        return [
            'trace_id' => $trace->id,
            'services' => $services,
            'calls' => \array_column($calls, 1),
        ];
    }

    /**
     * The other service that served the call: an entry transaction whose parent is the span, else a span.
     *
     * @param string $service The service of the span; empty when unknown.
     * @param list<Transaction> $transactions
     * @param list<Span> $spans
     * @return array{array|null, float|null, float|null} The callee, its start and its end.
     */
    private static function callee(Span $span, string $service, array $transactions, array $spans): array
    {
        if (\in_array($span->peer->type, ['db', 'cache', 'queue'], true)) {
            return [null, null, null];
        }

        foreach ($transactions as $t) {
            if ($t->parentSpanId === $span->spanId && $t->service !== null && $t->service !== '' && $t->service !== $service) {
                $json = self::calleeJson($t->service, $t->start, $t->end, $t->durationMs(), self::failed($t->status), $t->id);
                return [$json, $t->start, $t->end];
            }
        }

        foreach ($spans as $child) {
            if ($child->parentSpanId === $span->spanId
                && $child->service !== null && $child->service !== '' && $child->service !== $service
            ) {
                $json = self::calleeJson($child->service, $child->start, $child->end, $child->durationMs(), $child->isError(), null);
                return [$json, $child->start, $child->end];
            }
        }

        return [null, null, null];
    }

    private static function calleeJson(string $service, ?float $start, ?float $end, ?int $duration, bool $isError, ?string $transactionId): array
    {
        return [
            'service' => $service,
            'start_ts' => Json::time($start),
            'end_ts' => Json::time($end),
            'duration_ms' => $duration,
            'is_error' => $isError,
            'transaction_id' => $transactionId,
        ];
    }

    private static function failed(string $status): bool
    {
        return $status !== '' && $status !== 'ok' && $status !== 'unset';
    }
}
