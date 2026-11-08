<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Profiler;

use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Proto\Frame\Profiler\Type as PayloadType;

/**
 * @psalm-type Metadata = array{
 *     date: int,
 *     hostname: non-empty-string,
 *     filename?: non-empty-string,
 *     ...
 * }
 *
 * @psalm-type PayloadInput = array{
 *     type: non-empty-string,
 *     date: int,
 *     tags: array<non-empty-string, non-empty-string>,
 *     peaks: PeaksData,
 *     ...
 * }
 *
 * @psalm-import-type ProfileData from Profile
 * @psalm-import-type PeaksData from Peaks
 *
 * @internal
 * @psalm-internal Buggregator
 */
final class Payload implements \JsonSerializable
{
    private ?Profile $profile = null;

    /**
     * @param \Closure(): Profile $callsProvider
     */
    private function __construct(
        public readonly PayloadType $type,
        private readonly \Closure $callsProvider,
    ) {}

    /**
     * @param \Closure(): Profile $callsProvider
     */
    public static function new(
        PayloadType $type,
        \Closure $callsProvider,
    ): self {
        return new self($type, $callsProvider);
    }

    /**
     * @param PayloadInput $data
     */
    public static function fromArray(array $data, ?PayloadType $type = null): static
    {
        $provider = static fn(): Profile => Profile::fromArray($data);

        $type ??= PayloadType::from($data['type']);

        return new self(
            $type,
            $provider,
        );
    }

    public function getProfile(): Profile
    {
        return $this->profile ??= ($this->callsProvider)();
    }

    /**
     * @return array{type: non-empty-string}|ProfileData
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value] + $this->getProfile()->jsonSerialize();
    }

    /**
     * @return array{type: non-empty-string}|ProfileData
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
