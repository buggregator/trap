<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\FilesCarrier;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Traffic\Message;
use DateTimeImmutable;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Smtp extends Frame implements FilesCarrier
{
    public function __construct(
        public readonly Message\Smtp $message,
        DateTimeImmutable $time = new DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::SMTP, $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return \json_encode($this->message, \JSON_THROW_ON_ERROR);
    }

    public static function fromString(string $payload, DateTimeImmutable $time): self
    {
        $payload = \json_decode($payload, true, \JSON_THROW_ON_ERROR);
        $message = Message\Smtp::fromArray($payload);

        return new self($message, $time);
    }

    public function hasFiles(): bool
    {
        return \count($this->message->getAttachments()) > 0;
    }

    public function getFiles(): array
    {
        return $this->message->getAttachments();
    }
}
