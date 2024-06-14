<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;

/**
 * @psalm-import-type ProfileData from Profile
 *
 * @internal
 * @psalm-internal Buggregator
 */
final class Profiler extends Frame
{
    public function __construct(
        public readonly Frame\Profiler\Payload $payload,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Profiler, $time);
    }

    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        /** @var array{type: non-empty-string}|ProfileData $data */
        $data = Json::decode($payload);

        return new self(Frame\Profiler\Payload::fromArray($data), $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode($this->payload->jsonSerialize() + ['']);
    }
}
