<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler;

use Buggregator\Trap\Proto\Frame as BaseFrame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;

/**
 * @psalm-import-type PayloadInput from Frame\Payload
 *
 * @internal
 * @psalm-internal Buggregator
 */
final class Frame extends BaseFrame
{
    public function __construct(
        public readonly Frame\Payload $payload,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Profiler, $time);
    }

    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        /** @var PayloadInput $data */
        $data = Json::decode($payload);

        return new self(Frame\Payload::fromArray($data), $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode($this->payload->jsonSerialize() + ['']);
    }
}
