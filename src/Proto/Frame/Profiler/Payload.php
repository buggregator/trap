<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Profiler;

use Buggregator\Trap\Proto\Frame\Profiler\Type as PayloadType;

/**
 * @psalm-type Metadata = array{
 *     date: int,
 *     hostname: non-empty-string,
 *     filename?: non-empty-string,
 *     ...
 * }
 * @psalm-type Calls = array{
 *     edges: array,
 *     peaks: array
 * }
 *
 * @internal
 * @psalm-internal Buggregator
 */
class Payload implements \JsonSerializable
{
    /**
     * @param PayloadType $type
     * @param Metadata $metadata
     * @param \Closure(): Calls $callsProvider
     */
    private function __construct(
        public readonly PayloadType $type,
        private array $metadata,
        private \Closure $callsProvider,
    ) {
        $this->metadata['type'] = $type->value;
    }

    /**
     * @param PayloadType $type
     * @param Metadata $metadata
     * @param \Closure(): Calls $callsProvider
     */
    public static function new(
        PayloadType $type,
        array $metadata,
        \Closure $callsProvider,
    ): self {
        return new static($type, $metadata, $callsProvider);
    }

    public static function fromArray(array $data, ?Type $type = null): static
    {
        $metadata = $data;
        unset($metadata['edges'], $metadata['peaks']);
        return new static(
            $type ?? PayloadType::from($data['type']),
            $metadata,
            static fn(): array => $data,
        );
    }

    /**
     * @return Calls
     */
    public function getCalls(): array
    {
        return ($this->callsProvider)();
    }

    /**
     * @return Metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return ['type' => $this->type->value] + $this->getCalls() + $this->getMetadata();
    }

    /**
     * @return array{type: non-empty-string}&Calls&Metadata
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
