<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Sentry\SentryEnvelope;
use Buggregator\Trap\Proto\Frame\Sentry\SentryStore;
use DateTimeImmutable;

/**
 * @internal
 * @psalm-internal Buggregator
 */
abstract class Sentry extends Frame
{
    public static function fromString(string $payload, DateTimeImmutable $time): static
    {
        $data = \json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        return match (true) {
            $data['type'] === SentryEnvelope::SENTRY_FRAME_TYPE => SentryEnvelope::fromArray($data, $time),
            $data['type'] === SentryStore::SENTRY_FRAME_TYPE => SentryStore::fromArray($data, $time),
            default => throw new \InvalidArgumentException('Unknown Sentry frame type.'),
        };
    }
}
