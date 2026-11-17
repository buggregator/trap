<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tool\TextSearch;
use Buggregator\Trap\Module\Mcp\Tool\Time;
use Buggregator\Trap\Module\Mcp\Tool\TimeRange;
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
     * @param list<Wildcard> $services Any service when empty.
     */
    private function __construct(
        private readonly bool $errorsOnly,
        private readonly TimeRange $time,
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
            ...TextSearch::schema(
                'traces with a span whose name, status message, attribute, event (such as an exception) or '
                . 'resource attribute',
            ),
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
        $time = Time::range($arguments, $now);
        /** @var mixed $name */
        $name = $arguments['name'] ?? null;
        $name === null || \is_string($name) or throw new \InvalidArgumentException('`name` must be a string.');

        return new self(
            errorsOnly: Arguments::boolean($arguments, 'errors_only'),
            time: $time,
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
            && $this->time->contains($trace->updatedAt)
            && ($this->services === [] || $this->matchesService($trace->service))
            && ($this->name === null || $this->name->matches($trace->name ?? ''))
            && ($this->minDuration === null || self::durationMs($trace) >= $this->minDuration)
            && ($this->search === null || $this->search->matches(self::text($store, $trace->traceId)));
    }

    /**
     * A line each: the name of a span, its status message, the name of a span event such as `exception`,
     * and an attribute of a span, a span event or a resource as `key=value`.
     */
    private static function text(SpanStore $store, string $traceId): string
    {
        $lines = [];
        foreach ($store->getTrace($traceId) as $span) {
            $lines[] = $span->name;
            $span->status->message === '' or $lines[] = $span->status->message;
            self::attributes($span->attributes, $lines);
            foreach ($span->events as $event) {
                $lines[] = $event->name;
                self::attributes($event->attributes, $lines);
            }
        }

        foreach ($store->getResources($traceId) as $attributes) {
            self::attributes($attributes, $lines);
        }

        return \implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $attributes
     * @param list<string> $lines
     */
    private static function attributes(array $attributes, array &$lines): void
    {
        /** @var mixed $value */
        foreach ($attributes as $key => $value) {
            $lines[] = $key . '=' . (\is_scalar($value) ? (string) $value : self::json($value));
        }
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
