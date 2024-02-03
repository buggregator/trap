<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Profiler;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;
use DateTimeImmutable;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Payload extends Frame\Profiler
{
    public const PROFILE_FRAME_TYPE = 'payload';

    public function __construct(
        public array $payload,
        DateTimeImmutable $time = new DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Profiler, $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode($this->payload);
    }

    public static function fromArray(array $data, DateTimeImmutable $time): static
    {
        return new self($data, $time);
    }
}
