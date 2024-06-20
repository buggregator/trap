<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Profiler;

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
 * @psalm-import-type ProfileData from Profile
 *
 * @internal
 * @psalm-internal Buggregator
 */
final class Payload implements \JsonSerializable
{
    private ?Profile $profile = null;

    /**
     * @param PayloadType $type
     * @param \Closure(): Profile $callsProvider
     */
    private function __construct(
        public readonly PayloadType $type,
        private readonly \Closure $callsProvider,
    ) {}

    /**
     * @param PayloadType $type
     * @param \Closure(): Profile $callsProvider
     */
    public static function new(
        PayloadType $type,
        \Closure $callsProvider,
    ): self {
        return new self($type, $callsProvider);
    }

    /**
     * @param array{type: non-empty-string}|ProfileData $data
     */
    public static function fromArray(array $data, ?PayloadType $type = null): static
    {
        /**
         * @var \Closure(): Profile $provider
         * @psalm-suppress all
         */
        $provider = static fn(): Profile => Profile::fromArray($data);

        /** @psalm-suppress all */
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
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
