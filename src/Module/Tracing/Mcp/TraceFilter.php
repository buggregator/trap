<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tool\TextSearch;
use Buggregator\Trap\Module\Mcp\Tool\Time;
use Buggregator\Trap\Module\Mcp\Tool\Wildcard;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Module\Tracing\TraceSummary;
use Buggregator\Trap\Support\Json;

/**
 * Which traces {@see ListTraces} takes: `errors_only`, `from`, `to`, `service`, `name`, `min_duration_ms`,
 * `search` and `regex`.
 *
 * @internal
 */
final class TraceFilter
{
    /**
     * @param float|null $from Unix time in seconds of the earliest last update, inclusive.
     * @param float|null $to Unix time in seconds of the latest last update, inclusive.
     * @param list<Wildcard> $services Any service when empty.
     */
    private function __construct(
        private readonly bool $errorsOnly,
        private readonly ?float $from,
        private readonly ?float $to,
        private readonly array $services,
        private readonly ?Wildcard $name,
        private readonly ?float $minDuration,
        private readonly ?TextSearch $search,
    ) {}

    /**
     * @return array<non-empty-string, array<non-empty-string, mixed>> Properties of the input schema.
     */
    public static function schema(): array
    {
        return [
            'errors_only' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Only the traces with a span of the error status.',
            ],
            'from' => Time::schema('Only the traces last updated at this time or later.'),
            'to' => Time::schema('Only the traces last updated at this time or earlier.'),
            'service' => [
                'description' => 'Only the traces of this service or of any of these services; in any case, '
                    . 'with `*` for any characters.',
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                ],
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Only the traces whose name matches this, in any case, with `*` for any '
                    . 'characters, such as `GET /api/*`.',
            ],
            'min_duration_ms' => [
                'type' => 'number',
                'minimum' => 0,
                'description' => 'Only the traces that last this many milliseconds or longer.',
            ],
            ...TextSearch::schema('traces with a span whose name or attribute'),
        ];
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param float $now Unix time in seconds the durations of `from` and `to` count back from.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArguments(array $arguments, float $now): self
    {
        [$from, $to] = Time::range($arguments, $now);
        /** @var mixed $name */
        $name = $arguments['name'] ?? null;
        $name === null || \is_string($name) or throw new \InvalidArgumentException('`name` must be a string.');

        return new self(
            errorsOnly: Arguments::boolean($arguments, 'errors_only'),
            from: $from,
            to: $to,
            services: \array_map(Wildcard::compile(...), Arguments::strings($arguments, 'service')),
            name: $name === null ? null : Wildcard::compile($name),
            minDuration: Arguments::number($arguments, 'min_duration_ms'),
            search: TextSearch::fromArguments($arguments),
        );
    }

    public static function durationMs(TraceSummary $trace): float
    {
        return \round((float) Span::elapsed($trace->start, $trace->end) / 1e6, 3);
    }

    /**
     * @throws \InvalidArgumentException When the regular expression of the search fails on the trace.
     */
    public function matches(TraceSummary $trace, SpanStore $store): bool
    {
        return (!$this->errorsOnly || $trace->error)
            && ($this->from === null || $trace->updatedAt >= $this->from)
            && ($this->to === null || $trace->updatedAt <= $this->to)
            && ($this->services === [] || $this->matchesService($trace->service))
            && ($this->name === null || $this->name->matches($trace->name ?? ''))
            && ($this->minDuration === null || self::durationMs($trace) >= $this->minDuration)
            && ($this->search === null || $this->search->matches(self::text($store->getTrace($trace->traceId))));
    }

    /**
     * The span names and attributes, a line each, as `name` and `key=value`.
     *
     * @param array<array-key, Span> $spans
     */
    private static function text(array $spans): string
    {
        $lines = [];
        foreach ($spans as $span) {
            $lines[] = $span->name;
            /** @var mixed $value */
            foreach ($span->attributes as $key => $value) {
                $lines[] = $key . '=' . (\is_scalar($value) ? (string) $value : self::json($value));
            }
        }

        return \implode("\n", $lines);
    }

    private static function json(mixed $value): string
    {
        try {
            return Json::encode($value);
        } catch (\JsonException) {
            return '';
        }
    }

    private function matchesService(?string $service): bool
    {
        foreach ($this->services as $pattern) {
            if ($pattern->matches($service ?? '')) {
                return true;
            }
        }

        return false;
    }
}
