<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Message;

use Buggregator\Client\Traffic\Message\Multipart\Field;
use Buggregator\Client\Traffic\Message\Multipart\File;
use JsonSerializable;
use Psr\Http\Message\StreamInterface;

/**
 * @method StreamInterface getBody() Get full raw message body
 *
 * @psalm-import-type FieldDataArray from Field
 * @psalm-import-type FileDataArray from File
 *
 * @psalm-type SmtpDataArray = array{
 *     protocol: array<string, string|list<string>>,
 *     headers: array<string, list<string>>,
 *     messages: array<int, FieldDataArray>,
 *     attaches: array<int, FileDataArray>
 * }
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
     * @param SmtpDataArray $data
     */
    public static function fromArray(array $data): self
    {
        $self = new self($data['protocol'], $data['headers']);
        foreach ($data['messages'] as $message) {
            $self->messages[] = Field::fromArray($message);
        }
        foreach ($data['attaches'] as $attach) {
            $self->attaches[] = File::fromArray($attach);
        }

        return $self;
    }

    /**
     * @return SmtpDataArray
     */
    public function jsonSerialize(): array
    {
        return [
            'protocol' => $this->protocol,
            'headers' => $this->headers,
            'messages' => $this->messages,
            'attaches' => $this->attaches,
        ];
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
}
