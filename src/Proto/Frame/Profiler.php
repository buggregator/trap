<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Profiler\File;
use Buggregator\Trap\Proto\Frame\Profiler\Payload;
use Buggregator\Trap\Support\Json;
use DateTimeImmutable;

/**
 * @internal
 * @psalm-internal Buggregator
 */
abstract class Profiler extends Frame
{
    public static function fromString(string $payload, DateTimeImmutable $time): static
    {
        $data = Json::decode($payload);
        return match (true) {
            $data['type'] === File::PROFILE_FRAME_TYPE => File::fromArray($data, $time),
            $data['type'] === Payload::PROFILE_FRAME_TYPE => Payload::fromArray($data, $time),
            default => throw new \InvalidArgumentException('Unknown Profile frame type.'),
        };
    }
}
