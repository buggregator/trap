<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use DateTimeImmutable;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class SentryEnvelope extends Frame
{
    /**
     * @param array<string, string> $headers
     * @param list<Frame\SentryEnvelope\Item> $items
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $items,
        DateTimeImmutable $time = new DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Sentry, $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        // todo
        return \json_encode($this->headers, JSON_THROW_ON_ERROR);
    }

    public static function fromString(string $payload, DateTimeImmutable $time): Frame
    {
        // todo
        return new self(
            \json_decode($payload, true, JSON_THROW_ON_ERROR),
            \json_decode($payload, true, JSON_THROW_ON_ERROR),
            $time
        );
    }
}
