<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\FilesCarrier;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;
use Buggregator\Trap\Traffic\Message;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @psalm-internal Buggregator
 * @psalm-import-type TArrayData from Message\Smtp
 */
final class Smtp extends Frame implements FilesCarrier, \Buggregator\Trap\Proto\StreamCarrier
{
    public function __construct(
        public readonly Message\Smtp $message,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::SMTP, $time);
    }

    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        /** @var TArrayData $payload */
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

    public function getStream(): StreamInterface
    {
        return $this->message->getBody();
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode($this->message);
    }
}
