<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Otlp\Trace;

/**
 * Spans produced by one resource (usually one service instance).
 *
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type Attributes from Attributes
 * @psalm-import-type ScopeSpansData from ScopeSpans
 * @psalm-type ResourceSpansData = array{
 *     resource: array{
 *         attributes: Attributes,
 *         droppedAttributesCount: int<0, max>
 *     },
 *     schemaUrl: string,
 *     scopeSpans: list<ScopeSpansData>
 * }
 */
final class ResourceSpans implements \JsonSerializable
{
    /**
     * @param list<ScopeSpans> $scopeSpans
     * @param Attributes $resourceAttributes
     * @param int<0, max> $resourceDroppedAttributesCount
     */
    public function __construct(
        public readonly array $scopeSpans,
        public readonly array $resourceAttributes = [],
        public readonly int $resourceDroppedAttributesCount = 0,
        public readonly string $schemaUrl = '',
    ) {}

    /**
     * @param ResourceSpansData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            scopeSpans: \array_map(ScopeSpans::fromArray(...), $data['scopeSpans']),
            resourceAttributes: $data['resource']['attributes'],
            resourceDroppedAttributesCount: $data['resource']['droppedAttributesCount'],
            schemaUrl: $data['schemaUrl'],
        );
    }

    public function getServiceName(): ?string
    {
        $name = $this->resourceAttributes['service.name'] ?? null;
        return \is_string($name) ? $name : null;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'resource' => [
                'attributes' => Attributes::serialize($this->resourceAttributes),
                'droppedAttributesCount' => $this->resourceDroppedAttributesCount,
            ],
            'schemaUrl' => $this->schemaUrl,
            'scopeSpans' => $this->scopeSpans,
        ];
    }
}
