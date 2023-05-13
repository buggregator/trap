<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto;

use Buggregator\Client\ProtoType;
use DateTimeImmutable;

class Frame
{
    public function __construct(
        public readonly DateTimeImmutable $time,
        public readonly ProtoType $type,
        public readonly string $data,
    ) {
    }

    /**
     * @throws \Throwable
     */
    public static function fromString(string $string): self
    {
        $data = \json_decode($string, true, 2, \JSON_THROW_ON_ERROR);
        return new self(
            DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s.u',
                $data['time'],
            ),
            ProtoType::from($data['type']),
            $data['data'],
        );
    }

    public function __toString(): string
    {
        return \json_encode(
            [
                'time' => $this->time->format('Y-m-d H:i:s.u'),
                'type' => $this->type->value,
                'data' => $this->data,
            ],
            \JSON_THROW_ON_ERROR,
        );
    }
}