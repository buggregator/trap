<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Otlp\Trace;

/**
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type Attributes from Attributes
 * @psalm-type SpanLinkData = array{
 *     traceId: non-empty-string,
 *     spanId: non-empty-string,
 *     traceState: string,
 *     flags: int,
 *     attributes: Attributes,
 *     droppedAttributesCount: int<0, max>
 * }
 */
final class SpanLink implements \JsonSerializable
{
    /**
     * @param non-empty-string $traceId Lowercase hex, 32 chars.
     * @param non-empty-string $spanId Lowercase hex, 16 chars.
     * @param Attributes $attributes
     * @param int<0, max> $droppedAttributesCount
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly string $traceState = '',
        public readonly int $flags = 0,
        public readonly array $attributes = [],
        public readonly int $droppedAttributesCount = 0,
    ) {}

    /**
     * @param SpanLinkData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['traceId'],
            $data['spanId'],
            $data['traceState'],
            $data['flags'],
            $data['attributes'],
            $data['droppedAttributesCount'],
        );
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'traceId' => $this->traceId,
            'spanId' => $this->spanId,
            'traceState' => $this->traceState,
            'flags' => $this->flags,
            'attributes' => Attributes::serialize($this->attributes),
            'droppedAttributesCount' => $this->droppedAttributesCount,
        ];
    }
}
