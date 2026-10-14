<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Otlp\Trace;

/**
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type Attributes from Attributes
 * @psalm-import-type SpanEventData from SpanEvent
 * @psalm-import-type SpanLinkData from SpanLink
 * @psalm-import-type SpanStatusData from SpanStatus
 * @psalm-type SpanData = array{
 *     traceId: non-empty-string,
 *     spanId: non-empty-string,
 *     parentSpanId: non-empty-string|null,
 *     traceState: string,
 *     flags: int,
 *     name: string,
 *     kind: int<0, 5>,
 *     startTimeUnixNano: int,
 *     endTimeUnixNano: int,
 *     attributes: Attributes,
 *     droppedAttributesCount: int<0, max>,
 *     events: list<SpanEventData>,
 *     droppedEventsCount: int<0, max>,
 *     links: list<SpanLinkData>,
 *     droppedLinksCount: int<0, max>,
 *     status: SpanStatusData
 * }
 */
final class Span implements \JsonSerializable
{
    /**
     * @param non-empty-string $traceId Lowercase hex, 32 chars.
     * @param non-empty-string $spanId Lowercase hex, 16 chars.
     * @param non-empty-string|null $parentSpanId Null for a root span.
     * @param Attributes $attributes
     * @param list<SpanEvent> $events
     * @param list<SpanLink> $links
     * @param int<0, max> $droppedAttributesCount
     * @param int<0, max> $droppedEventsCount
     * @param int<0, max> $droppedLinksCount
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $name,
        public readonly SpanKind $kind,
        public readonly int $startTimeUnixNano,
        public readonly int $endTimeUnixNano,
        public readonly array $attributes = [],
        public readonly array $events = [],
        public readonly array $links = [],
        public readonly SpanStatus $status = new SpanStatus(),
        public readonly string $traceState = '',
        public readonly int $flags = 0,
        public readonly int $droppedAttributesCount = 0,
        public readonly int $droppedEventsCount = 0,
        public readonly int $droppedLinksCount = 0,
    ) {}

    /**
     * @param SpanData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            traceId: $data['traceId'],
            spanId: $data['spanId'],
            parentSpanId: $data['parentSpanId'],
            name: $data['name'],
            kind: SpanKind::from($data['kind']),
            startTimeUnixNano: $data['startTimeUnixNano'],
            endTimeUnixNano: $data['endTimeUnixNano'],
            attributes: $data['attributes'],
            events: \array_map(SpanEvent::fromArray(...), $data['events']),
            links: \array_map(SpanLink::fromArray(...), $data['links']),
            status: SpanStatus::fromArray($data['status']),
            traceState: $data['traceState'],
            flags: $data['flags'],
            droppedAttributesCount: $data['droppedAttributesCount'],
            droppedEventsCount: $data['droppedEventsCount'],
            droppedLinksCount: $data['droppedLinksCount'],
        );
    }

    /**
     * Nanoseconds from one Unix-nano timestamp to another.
     *
     * @return int<0, max> Zero when `$to` precedes `$from`; `PHP_INT_MAX` when the difference overflows.
     */
    public static function elapsed(int $from, int $to): int
    {
        if ($to <= $from) {
            return 0;
        }

        // int minus int turns into float on overflow.
        $elapsed = $to - $from;
        return \is_int($elapsed) && $elapsed > 0 ? $elapsed : \PHP_INT_MAX;
    }

    /**
     * @return int<0, max> Nanoseconds; zero when the end time precedes the start time.
     */
    public function getDuration(): int
    {
        return self::elapsed($this->startTimeUnixNano, $this->endTimeUnixNano);
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'traceId' => $this->traceId,
            'spanId' => $this->spanId,
            'parentSpanId' => $this->parentSpanId,
            'traceState' => $this->traceState,
            'flags' => $this->flags,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'startTimeUnixNano' => $this->startTimeUnixNano,
            'endTimeUnixNano' => $this->endTimeUnixNano,
            'attributes' => Attributes::serialize($this->attributes),
            'droppedAttributesCount' => $this->droppedAttributesCount,
            'events' => $this->events,
            'droppedEventsCount' => $this->droppedEventsCount,
            'links' => $this->links,
            'droppedLinksCount' => $this->droppedLinksCount,
            'status' => $this->status,
        ];
    }
}
