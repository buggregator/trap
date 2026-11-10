<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry;

use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore;
use Buggregator\Trap\Server\Proto\Frame as ProtoFrame;
use Buggregator\Trap\Support\Json;

/**
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type SentryStoreMessage from Frame\SentryStore
 * @psalm-import-type SentryEnvelopeMessage from Frame\SentryEnvelope
 */
abstract class Frame extends ProtoFrame
{
    public const TYPE = 'sentry';

    public function __construct(\DateTimeImmutable $time = new \DateTimeImmutable())
    {
        parent::__construct(self::TYPE, $time);
    }

    #[\Override]
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
