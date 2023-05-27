<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Message;

use Buggregator\Client\Traffic\Message\Multipart\Field;
use Buggregator\Client\Traffic\Message\Multipart\File;
use JsonSerializable;
use Psr\Http\Message\StreamInterface;

/**
 * @method StreamInterface getBody() Get full raw message body
 */
final class Smtp implements JsonSerializable
{
    use Headers;
    use StreamBody;

    /** @var Field[] */
    private array $messages = [];

    /** @var File[] */
    private array $attaches = [];

    private function __construct(
        private array $protocol,
        array $headers,
    ) {
        $this->setHeaders($headers);
    }

    public static function create(array $protocol, array $headers): self
    {
        return new self($protocol, $headers);
    }

    /**
     * @return Field[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return File[]
     */
    public function getAttaches(): array
    {
        return $this->attaches;
    }

    /**
     * @param Field[] $texts
     */
    public function withTexts(array $texts): self
    {
        $clone = clone $this;
        $clone->messages = $texts;
        return $clone;
    }

    /**
     * @param File[] $attaches
     */
    public function withAttaches(array $attaches): self
    {
        $clone = clone $this;
        $clone->attaches = $attaches;
        return $clone;
    }

    public function getSender(): string
    {
        return $this->protocol['FROM'] ?? $this->getHeaderLine('From');
    }

    /**
     * BCCs are recipients passed as RCPTs but not
     * in the body of the mail.
     *
     * @return non-empty-string[]
     */
    private function getBcc(): array
    {
        return $this->protocol['BCC'] ?? [];
    }

    public function jsonSerialize(): array
    {
        return [
            'protocol' => $this->protocol,
            'headers' => $this->headers,
            'messages' => $this->messages,
            'attaches' => $this->attaches,
        ];
    }
}
