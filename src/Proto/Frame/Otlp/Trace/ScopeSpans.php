<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Otlp\Trace;

/**
 * Spans produced by one instrumentation scope.
 *
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type Attributes from Attributes
 * @psalm-import-type SpanData from Span
 * @psalm-type ScopeSpansData = array{
 *     scope: array{
 *         name: string,
 *         version: string,
 *         attributes: Attributes,
 *         droppedAttributesCount: int<0, max>
 *     },
 *     schemaUrl: string,
 *     spans: list<SpanData>
 * }
 */
final class ScopeSpans implements \JsonSerializable
{
    /**
     * @param list<Span> $spans
     * @param Attributes $scopeAttributes
     * @param int<0, max> $scopeDroppedAttributesCount
     */
    public function __construct(
        public readonly array $spans,
        public readonly string $scopeName = '',
        public readonly string $scopeVersion = '',
        public readonly array $scopeAttributes = [],
        public readonly int $scopeDroppedAttributesCount = 0,
        public readonly string $schemaUrl = '',
    ) {}

    /**
     * @param ScopeSpansData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            spans: \array_map(Span::fromArray(...), $data['spans']),
            scopeName: $data['scope']['name'],
            scopeVersion: $data['scope']['version'],
            scopeAttributes: $data['scope']['attributes'],
            scopeDroppedAttributesCount: $data['scope']['droppedAttributesCount'],
            schemaUrl: $data['schemaUrl'],
        );
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'scope' => [
                'name' => $this->scopeName,
                'version' => $this->scopeVersion,
                'attributes' => Attributes::serialize($this->scopeAttributes),
                'droppedAttributesCount' => $this->scopeDroppedAttributesCount,
            ],
            'schemaUrl' => $this->schemaUrl,
            'spans' => $this->spans,
        ];
    }
}
