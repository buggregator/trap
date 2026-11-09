<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer;

use Buggregator\Trap\Proto\FilesCarrier;
use Buggregator\Trap\Proto\Frame as BaseFrame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @psalm-internal Buggregator
 * @psalm-import-type TArrayData from Message
 */
final class Frame extends BaseFrame implements FilesCarrier, \Buggregator\Trap\Proto\StreamCarrier
{
    public function __construct(
        public readonly Message $message,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::SMTP, $time);
    }

    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        /** @var TArrayData $data */
        $data = \json_decode($payload, true, 64, \JSON_THROW_ON_ERROR);
        $message = Message::fromArray($data);

        return new self($message, $time);
    }

    #[\Override]
    public function hasFiles(): bool
    {
        return \count($this->message->getAttachments()) > 0;
    }

    #[\Override]
    public function getFiles(): array
    {
        return $this->message->getAttachments();
    }

    #[\Override]
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
