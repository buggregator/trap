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

        $index = new TraceIndex($trace, $project);
        $calls = [];
        foreach ($spans as $span) {
            $service = $span->service ?? $serviceOf[(string) $span->transactionId] ?? '';
            $from = $service === '' ? 'unknown' : $service;

            [$callee, $calleeStart, $calleeEnd] = self::callee($index->callee($span, $service));
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
                // Truncated toward zero, as buggregator/server does: a callee that ended 0.4 ms late did not
                // keep working. Microseconds first, so float noise of the times does not cut a whole ms.
                'late_ms' => $callee !== null && $calleeEnd !== null && $span->end !== null
                    ? \intdiv((int) \round(($calleeEnd - $span->end) * 1_000_000.0), 1000)
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
     * @return array{array|null, float|null, float|null} The callee as JSON, its start and its end.
     */
    private static function callee(Transaction|Span|null $callee): array
    {
        if ($callee === null) {
            return [null, null, null];
        }

        $transaction = $callee instanceof Transaction;
        $json = [
            'service' => (string) $callee->service,
            'start_ts' => Json::time($callee->start),
            'end_ts' => Json::time($callee->end),
            'duration_ms' => $callee->durationMs(),
            'is_error' => $transaction ? self::failed($callee->status) : $callee->isError(),
            'transaction_id' => $transaction ? $callee->id : null,
        ];

        return [$json, $callee->start, $callee->end];
    }

    private static function failed(string $status): bool
    {
        return $status !== '' && $status !== 'ok' && $status !== 'unset';
    }
}
