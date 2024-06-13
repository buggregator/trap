<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Binary extends Frame implements \Buggregator\Trap\Proto\StreamCarrier
{
    public function __construct(
        public readonly StreamInterface $stream,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Binary, $time);
    }

    public static function fromString(string $payload, \DateTimeImmutable $time): never
    {
        throw new \RuntimeException('Binary data can not be restored from string.');
    }

    public function getSize(): int
    {
        return (int) $this->stream->getSize();
    }

    public function getStream(): StreamInterface
    {
        return $this->stream;
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode([
            'size' => $this->getSize(),
        ]);
    }
}
