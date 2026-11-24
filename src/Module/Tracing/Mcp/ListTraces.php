<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tool\Cursor;
use Buggregator\Trap\Module\Mcp\Tool\Time;
use Buggregator\Trap\Server\Telemetry\Insight\TraceInsights;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;

/**
 * The traces of the {@see TelemetryStore} updated after a cursor that match a {@see TraceFilter},
 * the least recently updated first, with a summary each: the traces of Sentry SDKs and of OTLP exporters alike.
 *
 * @internal
 */
final class ListTraces implements Tool
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    public function __construct(
        private readonly TelemetryStore $store,
        private readonly TraceInsights $insights,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'list_traces';
    }

    #[\Override]
    public function title(): string
    {
        return 'List traces';
    }

    #[\Override]
    public function description(): string
    {
        return 'Lists the traces Trap has received: Sentry transactions and spans, and OpenTelemetry spans over '
            . 'OTLP. Each trace comes with its ID, name, services, sources, number of spans, duration, whether '
            . 'a span failed and the number of `findings`, problems such as repeated queries (N+1) that '
            . '`get_trace` details. Spans of a trace may arrive in several requests, so a trace is updated as they '
            . 'come, and an updated trace is listed again. Returns the traces updated after the `since` cursor, least '
            . 'recently updated first, and a new opaque `cursor`: to see only the traces of the code you run next, '
            . 'call it once, keep the cursor, run the code, then call it with `since` set to that cursor. When '
            . '`has_more` is true, call again with the returned cursor to get the rest. Narrow the list by `project`, '
            . '`source`, `errors_only`, `service`, `name`, `min_duration_ms`, the time of the last update (`from`, '
            . '`to`, such as `5m` for the last five minutes) and the text of span names and attributes (`search`). '
            . 'Read a trace as a tree of spans with `get_trace`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'since' => [
                    'type' => 'string',
                    'description' => 'The opaque `cursor` of a previous call: only the traces updated after it. '
                        . 'All the traces when omitted or empty, and for a cursor of an earlier run of Trap.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                    'description' => 'The most traces to return.',
                ],
                ...TraceFilter::schema(),
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'traces' => [
                    'type' => 'array',
                    'description' => 'The least recently updated first.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'The trace ID to pass to `get_trace`.'],
                            'name' => [
                                'type' => ['string', 'null'],
                                'description' => 'The name of the root transaction or span; of the earliest span '
                                    . 'whose parent has not arrived while the root is missing.',
                            ],
                            'service' => [
                                'type' => ['string', 'null'],
                                'description' => 'The service of the root; of another span when the root has none.',
                            ],
                            'services' => $strings + ['description' => 'Every service of the trace.'],
                            'sources' => $strings + [
                                'description' => '`sentry` for the parts of Sentry SDKs, `otel` for those of OTLP.',
                            ],
                            'projects' => $strings,
                            'spans' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Spans and transactions.'],
                            'duration_ms' => [
                                'type' => 'number',
                                'minimum' => 0,
                                'description' => 'From the earliest span start to the latest span end.',
                            ],
                            'error' => ['type' => 'boolean', 'description' => 'Whether a span or a transaction failed.'],
                            'findings' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'description' => 'How many problems `get_trace` lists for the trace, such as repeated '
                                    . 'queries (N+1).',
                            ],
                            'updated' => ['type' => 'string', 'description' => 'When the last span arrived, ISO 8601.'],
                        ],
                        'required' => [
                            'id', 'name', 'service', 'services', 'sources', 'projects', 'spans', 'duration_ms', 'error',
                            'findings', 'updated',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'cursor' => ['type' => 'string', 'description' => 'The `since` of the next call.'],
                'has_more' => ['type' => 'boolean', 'description' => 'Whether more updated traces wait for the next call.'],
            ],
            'required' => ['traces', 'cursor', 'has_more'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    /**
     * @return array{
     *     traces: list<array{
     *         id: non-empty-string,
     *         name: string|null,
     *         service: string|null,
     *         services: list<non-empty-string>,
     *         sources: list<'sentry'|'otel'>,
     *         projects: list<non-empty-string>,
     *         spans: int,
     *         duration_ms: float,
     *         error: bool,
     *         findings: int<0, max>,
     *         updated: string,
     *     }>,
     *     cursor: non-empty-string,
     *     has_more: bool,
     * }
     */
    #[\Override]
    public function call(array $arguments): array
    {
        $since = Cursor::since($arguments, $this->store->id, $this->store->cursor());
        $limit = Arguments::integer($arguments, 'limit', 1, self::MAX_LIMIT) ?? self::DEFAULT_LIMIT;
        $filter = TraceFilter::fromArguments($arguments, \microtime(true));

        $cursor = $this->store->cursor();
        $traces = [];
        $hasMore = false;
        $last = 0;
        foreach ($this->store->traces() as $trace) {
            if ($trace->seq <= $since) {
                continue;
            }

            $summary = TraceSummary::of($trace, $filter->projects);
            if ($summary === null || !$filter->matches($summary)) {
                continue;
            }

            if (\count($traces) === $limit) {
                // The rest comes with the next call, which takes the last listed trace as its cursor
                $hasMore = true;
                $cursor = $last;
                break;
            }

            $last = $trace->seq;
            $traces[] = $this->describe($summary, $filter->projects);
        }

        return ['traces' => $traces, 'cursor' => Cursor::encode($this->store->id, $cursor), 'has_more' => $hasMore];
    }

    /**
     * @param list<non-empty-string> $projects
     * @return array{
     *     id: non-empty-string,
     *     name: string|null,
     *     service: string|null,
     *     services: list<non-empty-string>,
     *     sources: list<'sentry'|'otel'>,
     *     projects: list<non-empty-string>,
     *     spans: int,
     *     duration_ms: float,
     *     error: bool,
     *     findings: int<0, max>,
     *     updated: string,
     * }
     */
    private function describe(TraceSummary $trace, array $projects): array
    {
        return [
            'id' => $trace->trace->id,
            'name' => $trace->name,
            'service' => $trace->service,
            'services' => $trace->services,
            'sources' => $trace->sources,
            'projects' => $trace->projects,
            'spans' => $trace->count(),
            'duration_ms' => $trace->durationMs(),
            'error' => $trace->error,
            'findings' => \count($this->insights->of($trace->trace, $projects)),
            'updated' => Time::format($trace->trace->updatedAt),
        ];
    }
}
