<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend\Api;

use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Telemetry\Trace;
use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * Services and the calls between them over a period, as buggregator/server draws its service map.
 *
 * A call is a span with a peer address. Its target is the other service whose entry transaction, or span,
 * names the call as its parent; else the peer address, always the address for a database, cache or queue.
 * A service is whoever served a request (an entry transaction with a service name) or made a call; any other
 * target is an external node, measured by the calls into it.
 *
 * @internal
 *
 * @psalm-type MapCall = array{source: string, target: string, opType: string, isError: bool, duration: int|null, start: float|null, traceId: string}
 */
final class ServiceMap
{
    /** Traces of an edge listed inline; the rest come from the edge endpoint. */
    private const EDGE_TRACES = 20;

    /**
     * @param list<MapCall> $calls
     * @param list<Transaction> $entries
     */
    private function __construct(
        private readonly array $calls,
        private readonly array $entries,
        private readonly ?float $from,
        private readonly ?float $to,
    ) {}

    /**
     * @param non-empty-string|null $project
     * @param string|null $window `15m`, `24h`, a number of minutes, or `all`; the last hour when empty.
     *        Ignored when `from` or `to` is given.
     */
    public static function read(
        TelemetryStore $store,
        ?string $project,
        ?string $window,
        ?string $from,
        ?string $to,
        float $now,
    ): self {
        [$fromTime, $toTime] = self::period($window, $from, $to, $now);
        $inPeriod = static fn(?float $time): bool => ($fromTime === null || ($time !== null && $time >= $fromTime))
            && ($toTime === null || ($time !== null && $time < $toTime + 1.0));

        $calls = [];
        $entries = [];
        foreach ($store->traces() as $trace) {
            foreach ($trace->transactions as $transaction) {
                ($project === null || $transaction->project === $project) && $inPeriod($transaction->start)
                    and $entries[] = $transaction;
            }

            foreach ($trace->spans as $span) {
                if ($span->peer->address === null
                    || ($project !== null && $span->project !== $project)
                    || !$inPeriod($span->start)
                ) {
                    continue;
                }

                $calls[] = [
                    'source' => $span->service ?? 'unknown',
                    'target' => self::callee($trace, $span) ?? $span->peer->address,
                    'opType' => $span->peer->type,
                    'isError' => $span->isError(),
                    'duration' => $span->durationMs(),
                    'start' => $span->start,
                    'traceId' => $trace->id,
                ];
            }
        }

        return new self($calls, $entries, $fromTime, $toTime);
    }

    /**
     * The other service that served a call: by its entry transaction, else by a span, else by a span
     * of a transaction, whose parent is the calling span. Databases, caches and queues have none.
     */
    public static function callee(Trace $trace, Span $span): ?string
    {
        if (\in_array($span->peer->type, ['db', 'cache', 'queue'], true)) {
            return null;
        }

        $other = static fn(?string $service): bool => $service !== null && $service !== ($span->service ?? '');
        $entries = \array_filter(
            $trace->transactions,
            static fn(Transaction $t): bool => $t->parentSpanId === $span->spanId,
        );
        foreach ($entries as $transaction) {
            if ($other($transaction->service)) {
                return $transaction->service;
            }
        }

        foreach ($trace->spans as $child) {
            if ($child->parentSpanId === $span->spanId && $other($child->service)) {
                return $child->service;
            }
        }

        foreach ($entries as $transaction) {
            foreach ($trace->spans as $child) {
                if ($child->transactionId === $transaction->id && $other($child->service)) {
                    return $child->service;
                }
            }
        }

        return null;
    }

    /**
     * Nothing given: the last 60 minutes. A plain number of `window` is minutes, up to a week.
     *
     * @return array{float|null, float|null} Unix seconds; null for no bound.
     */
    public static function period(?string $window, ?string $from, ?string $to, float $now): array
    {
        $fromTime = Scope::parseTime($from);
        $toTime = Scope::parseTime($to);
        if ($fromTime !== null || $toTime !== null) {
            return [$fromTime, $toTime];
        }

        $window = \trim((string) $window);
        return match (true) {
            $window === '' => [$now - 3600.0, null],
            $window === 'all' || $window === '0' => [null, null],
            \ctype_digit($window) && (int) $window <= 10080 => [$now - (float) ((int) $window * 60), null],
            default => [$now - (float) (Scope::parseWindow($window) ?? 3600), null],
        };
    }

    public function json(float $now): array
    {
        [$from, $to] = $this->bounds($now);
        $buckets = new Buckets($from, $to);
        $minutes = ($to - $from) / 60.0;

        /** @var array<string, Stats> $services */
        $services = [];
        foreach ($this->entries as $entry) {
            if ($entry->service !== null && $entry->service !== '') {
                ($services[$entry->service] ??= new Stats())->add(
                    $buckets,
                    $entry->start,
                    $entry->durationMs(),
                    self::failed($entry->status),
                );
            }
        }

        /** @var array<string, array{source: string, target: string, opType: string, stats: Stats, calls: list<MapCall>}> $edges */
        $edges = [];
        foreach ($this->calls as $call) {
            $services[$call['source']] ??= new Stats();
            $key = "{$call['source']}\0{$call['target']}\0{$call['opType']}";
            $edges[$key] ??= ['source' => $call['source'], 'target' => $call['target'], 'opType' => $call['opType'], 'stats' => new Stats(), 'calls' => []];
            $edges[$key]['stats']->add($buckets, $call['start'], $call['duration'], $call['isError']);
            $edges[$key]['calls'][] = $call;
        }

        $edges = \array_values($edges);
        \usort($edges, static fn(array $a, array $b): int => [$b['stats']->count, $a['source'], $a['target']]
            <=> [$a['stats']->count, $b['source'], $b['target']]);

        /** @var array<string, Stats> $externals */
        $externals = [];
        /** @var array<string, string> $externalTypes */
        $externalTypes = [];
        $edgesJson = [];
        foreach ($edges as $edge) {
            $target = $edge['target'];
            if (!isset($services[$target])) {
                if (!isset($externals[$target])) {
                    $externals[$target] = new Stats();
                    $externalTypes[$target] = $edge['opType'];
                }
                foreach ($edge['calls'] as $call) {
                    $externals[$target]->add($buckets, $call['start'], $call['duration'], $call['isError']);
                }
            }

            $traces = self::edgeTraces($edge['calls']);
            $edgesJson[] = $edge['stats']->json($buckets, $minutes) + [
                'source' => $edge['source'],
                'target' => $target,
                'op_type' => $edge['opType'],
                'trace_count' => \count($traces),
                'traces' => \array_slice($traces, 0, self::EDGE_TRACES),
            ];
        }

        $nodes = [];
        \ksort($services, \SORT_STRING);
        foreach ($services as $name => $stats) {
            $name = (string) $name;
            $nodes[] = ['id' => $name, 'label' => $name, 'kind' => 'service'] + $stats->json($buckets, $minutes);
        }
        \ksort($externals, \SORT_STRING);
        foreach ($externals as $name => $stats) {
            $name = (string) $name;
            $nodes[] = ['id' => $name, 'label' => self::label($name, $externalTypes[$name]), 'kind' => 'external']
                + $stats->json($buckets, $minutes);
        }

        return [
            'from' => Json::time($from),
            'to' => Json::time($to),
            'bucket_seconds' => $buckets->step,
            'window_minutes' => (int) \round($minutes),
            'nodes' => $nodes,
            'edges' => $edgesJson,
        ];
    }

    /**
     * The traces through one edge, errors first, then the newest.
     *
     * @param string|null $opType Any when null or empty: tells two edges of one pair apart.
     * @return list<array{trace_id: string, start_ts: string|null, duration_ms: int, is_error: bool}>
     */
    public function edge(string $source, string $target, ?string $opType): array
    {
        return self::edgeTraces(\array_values(\array_filter(
            $this->calls,
            static fn(array $call): bool => $call['source'] === $source && $call['target'] === $target
                && ($opType === null || $opType === '' || $call['opType'] === $opType),
        )));
    }

    /**
     * Calls of a trace grouped: the earliest start, the longest duration, an error when any call failed.
     *
     * @param list<MapCall> $calls
     * @return list<array{trace_id: string, start_ts: string|null, duration_ms: int, is_error: bool}>
     */
    private static function edgeTraces(array $calls): array
    {
        /** @var array<string, array{id: string, start: float|null, duration: int, error: bool}> $traces */
        $traces = [];
        foreach ($calls as $call) {
            $trace = $traces[$call['traceId']] ?? ['id' => $call['traceId'], 'start' => $call['start'], 'duration' => 0, 'error' => false];
            $call['start'] !== null && ($trace['start'] === null || $call['start'] < $trace['start'])
                and $trace['start'] = $call['start'];
            $trace['duration'] = \max($trace['duration'], $call['duration'] ?? 0);
            $trace['error'] = $trace['error'] || $call['isError'];
            $traces[$call['traceId']] = $trace;
        }

        $traces = \array_values($traces);
        \usort($traces, static fn(array $a, array $b): int => [!$a['error'], -($a['start'] ?? 0.0), $a['id']]
            <=> [!$b['error'], -($b['start'] ?? 0.0), $b['id']]);

        return \array_map(static fn(array $trace): array => [
            'trace_id' => $trace['id'],
            'start_ts' => Json::time($trace['start']),
            'duration_ms' => $trace['duration'],
            'is_error' => $trace['error'],
        ], $traces);
    }

    /**
     * A transaction failed when its status is set and not `ok`.
     */
    private static function failed(string $status): bool
    {
        return $status !== '' && $status !== 'ok' && $status !== 'unset';
    }

    private static function label(string $address, string $type): string
    {
        return match ($type) {
            'db' => "DB: $address",
            'cache' => "Cache: $address",
            'queue' => "Queue: $address",
            default => $address,
        };
    }

    /**
     * Unbounded sides become the data seen and now; an empty or reversed period is the last hour or a minute.
     *
     * @return array{float, float}
     */
    private function bounds(float $now): array
    {
        $seen = [];
        foreach ($this->calls as $call) {
            $call['start'] === null or $seen[] = $call['start'];
        }
        foreach ($this->entries as $entry) {
            $entry->start === null or $seen[] = $entry->start;
        }

        $to = $this->to ?? \max($now, ...$seen);
        $from = $this->from;
        if ($from === null) {
            $from = $seen === [] ? $to : \min($to, ...$seen);
            $from < $to or $from = $to - 3600.0;
        }
        $from < $to or $to = $from + 60.0;

        return [$from, $to];
    }
}
