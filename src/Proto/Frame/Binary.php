<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use DateTimeImmutable;
use Psr\Http\Message\StreamInterface;

final class Binary extends Frame
{
    public function __construct(
        public readonly StreamInterface $stream,
        DateTimeImmutable $time = new DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Binary, $time);
    }

    public function getSize(): int
    {
        return (int)$this->stream->getSize();
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return \json_encode([
            'size' => $this->getSize(),
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromString(string $payload, DateTimeImmutable $time): never
    {
        throw new \RuntimeException('Binary data can not be restored from string.');
    }
}
