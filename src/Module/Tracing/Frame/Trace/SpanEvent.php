<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Frame\Trace;

/**
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type AttributeMap from Attributes
 * @psalm-type SpanEventData = array{
 *     timeUnixNano: int,
 *     name: string,
 *     attributes: AttributeMap,
 *     droppedAttributesCount: int<0, max>
 * }
 */
final class SpanEvent implements \JsonSerializable
{
    /**
     * @param AttributeMap $attributes
     * @param int<0, max> $droppedAttributesCount
     */
    public function __construct(
        public readonly int $timeUnixNano,
        public readonly string $name,
        public readonly array $attributes = [],
        public readonly int $droppedAttributesCount = 0,
    ) {}

    /**
     * @param SpanEventData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['timeUnixNano'],
            $data['name'],
            $data['attributes'],
            $data['droppedAttributesCount'],
        );
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'timeUnixNano' => $this->timeUnixNano,
            'name' => $this->name,
            'attributes' => Attributes::serialize($this->attributes),
            'droppedAttributesCount' => $this->droppedAttributesCount,
        ];
    }
}
