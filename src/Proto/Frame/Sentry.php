<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Sentry\SentryEnvelope;
use Buggregator\Trap\Proto\Frame\Sentry\SentryStore;
use Buggregator\Trap\Support\Json;

/**
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type SentryStoreMessage from Sentry\SentryStore
 * @psalm-import-type SentryEnvelopeMessage from Sentry\SentryEnvelope
 */
abstract class Sentry extends Frame
{
    final public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        static::class === self::class or throw new \LogicException(
            \sprintf('Factory method must be called from %s class.', self::class),
        );

        /** @var array{type: string, ...mixed} $data */
        $data = Json::decode($payload);

        /** @psalm-suppress InvalidArgument */
        $result = match ($data['type']) {
            SentryEnvelope::SENTRY_FRAME_TYPE => SentryEnvelope::fromArray($data, $time),
            SentryStore::SENTRY_FRAME_TYPE => SentryStore::fromArray($data, $time),
            default => throw new \InvalidArgumentException('Unknown Sentry frame type.'),
        };

        return $result;
    }
}
