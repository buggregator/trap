<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto;

use Buggregator\Client\ProtoType;
use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;

final class HttpFrame extends Frame
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        DateTimeImmutable $time = new DateTimeImmutable(),
    ) {
        parent::__construct(type: ProtoType::HTTP, time: $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return \json_encode($this->request, JSON_THROW_ON_ERROR);
    }
}
