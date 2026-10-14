<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Monolog extends Frame
{
    public function __construct(
        public readonly array $message,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Monolog, $time);
    }

    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        $message = Json::decode($payload);
        \is_array($message) or throw new \JsonException('The Monolog frame payload must be a JSON object.');

        return new self($message, $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode($this->message);
    }
}
