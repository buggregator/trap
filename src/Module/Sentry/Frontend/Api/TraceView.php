<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend\Api;

use Buggregator\Trap\Server\Telemetry\Insight\Finding;
use Buggregator\Trap\Server\Telemetry\Insight\RepeatedQuery;
use Buggregator\Trap\Server\Telemetry\Insight\TraceInsights;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Trace;
use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * A stored trace as the trace list and the trace page of the UI show it.
 *
 * @internal
 */
final class TraceView
{
    /**
     * @param non-empty-string|null $project Only the part of the trace sent to this project.
     */
    public function __construct(
        private readonly Trace $trace,
        private readonly ?string $project = null,
    ) {}

    /**
     * A row of the trace list, for the root transaction of the trace. The duration covers the whole trace, work
     * that ends after the root does included, so that the preview spans fit in it.
     */
    public function row(Transaction $root): array
    {
        $spans = $this->trace->spans($this->project);
        $errors = 0;
        foreach ($spans as $span) {
            $span->isError() and ++$errors;
        }

        [$sources, $services] = $this->sourcesAndServices();
        [$start, $end] = $this->extent();

        return [
            'trace_id' => $this->trace->id,
            'transaction_id' => $root->id,
            'transaction_name' => $root->name,
            'op' => $root->op,
            'status' => $root->status,
            'duration_ms' => Span::durationBetween($start, $end),
            'span_count' => \count($spans),
            'error_count' => $errors,
            'received_at' => Json::time($root->start),
            'preview_spans' => $this->preview($start, 3),
            'sources' => $sources,
            'services' => $services,
        ];
    }

    /**
     * The longest spans, for the mini waterfall of a list row.
     *
     * @param int<1, max> $limit
     * @return list<array>
     */
    public function preview(?float $base, int $limit): array
    {
        $spans = $this->trace->spans($this->project);
        \usort($spans, static fn(Span $a, Span $b): int => ($b->durationMs() ?? -1) <=> ($a->durationMs() ?? -1));

        return \array_map(static fn(Span $span): array => [
            'span_id' => $span->spanId,
            'op' => $span->op,
            'description' => $span->description,
            'start_offset_ms' => Json::offsetMs($base, $span->start),
            'duration_ms' => $span->durationMs(),
            'is_error' => $span->isError(),
            'peer_type' => $span->peer->type,
        ], \array_slice($spans, 0, $limit));
    }

    /**
     * The trace page: the root transaction as the header, every span and the entry span of every other
     * service as one tree under it, and the repeated queries of {@see RepeatedQuery} as `n_plus_one`.
     *
     * @param list<Finding> $findings Of {@see TraceInsights}, for the same projects.
     */
    public function detail(Transaction $root, array $findings = []): array
    {
        $base = $root->start;
        $spans = [];
        foreach ($this->trace->spans($this->project) as $span) {
            $spans[$span->spanId] = self::span($span, $base);
        }

        // Every other transaction is the span where a request entered another service; a span of the same ID wins.
        foreach ($this->trace->transactions($this->project) as $transaction) {
            if ($transaction === $root || $transaction->spanId === null || isset($spans[$transaction->spanId])) {
                continue;
            }

            $spans[$transaction->spanId] = [
                'span_id' => $transaction->spanId,
                'parent_span_id' => $transaction->parentSpanId,
                'op' => $transaction->op,
                'description' => $transaction->name,
                'status' => $transaction->status,
                'start_offset_ms' => Json::offsetMs($base, $transaction->start),
                'duration_ms' => $transaction->durationMs(),
                'peer_type' => null,
                'peer_address' => null,
                'is_error' => $transaction->isError(),
                'source' => $transaction->source->value,
                'service_name' => $transaction->service,
                'attributes' => Json::map($transaction->attributes),
                'is_transaction' => true,
                'transaction_id' => $transaction->id,
            ];
        }

        $spans = \array_values($spans);
        \usort($spans, static fn(array $a, array $b): int => $a['start_offset_ms'] <=> $b['start_offset_ms']);

        return [
            'trace_id' => $this->trace->id,
            'transaction' => [
                'id' => $root->id,
                'transaction_name' => $root->name,
                'op' => $root->op,
                'status' => $root->status,
                'start_ts' => Json::time($root->start),
                'end_ts' => Json::time($root->end),
                'duration_ms' => $root->durationMs(),
                'sources' => $this->sourcesAndServices()[0],
                'span_id' => $root->spanId,
                'source' => $root->source->value,
                'service_name' => $root->service,
            ],
            'spans' => $spans,
            'n_plus_one' => \array_values(\array_map(static fn(Finding $finding): array => [
                'transaction_id' => $finding->transactionId,
                'query' => $finding->summary,
                'count' => $finding->count,
                'total_ms' => $finding->totalMs,
                'origin' => $finding->origin,
                'span_ids' => $finding->spanIds,
            ], \array_filter($findings, static fn(Finding $finding): bool => $finding->rule === RepeatedQuery::NAME))),
        ];
    }

    /**
     * Where the data came from, Sentry first, and the services in the order they started.
     *
     * @return array{list<non-empty-string>, list<string>}
     */
    public function sourcesAndServices(): array
    {
        $sources = [];
        $services = [];
        foreach ([...$this->trace->transactions($this->project), ...$this->trace->spans($this->project)] as $item) {
            $sources[$item->source->value] = true;
            if ($item->service !== null && $item->service !== '') {
                $start = $item->start ?? \INF;
                $services[$item->service] = \min($services[$item->service] ?? \INF, $start);
            }
        }

        $sources = \array_keys($sources);
        \usort($sources, static fn(string $a, string $b): int => [$a !== Source::Sentry->value, $a] <=> [$b !== Source::Sentry->value, $b]);
        \uksort($services, static fn(string|int $a, string|int $b): int => [$services[$a], (string) $a] <=> [$services[$b], (string) $b]);

        return [$sources, \array_map('strval', \array_keys($services))];
    }

    private static function span(Span $span, ?float $base): array
    {
        return [
            'span_id' => $span->spanId,
            'parent_span_id' => $span->parentSpanId,
            'op' => $span->op,
            'description' => $span->description,
            'status' => $span->status,
            'start_offset_ms' => Json::offsetMs($base, $span->start),
            'duration_ms' => $span->durationMs(),
            'peer_type' => $span->peer->type,
            'peer_address' => $span->peer->address,
            'is_error' => $span->isError(),
            'source' => $span->source->value,
            'service_name' => $span->service,
            'attributes' => Json::map($span->attributes),
        ];
    }

    /**
     * @return array{float|null, float|null} The earliest start and the latest end of the transactions and spans.
     */
    private function extent(): array
    {
        $start = $end = null;
        foreach ([...$this->trace->transactions($this->project), ...$this->trace->spans($this->project)] as $item) {
            $item->start === null or $start = \min($start ?? \INF, $item->start);
            $item->end === null or $end = \max($end ?? -\INF, $item->end);
        }

        return [$start, $end];
    }
}
