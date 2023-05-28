<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Message;

use Buggregator\Client\Traffic\Message\Multipart\Field;
use Buggregator\Client\Traffic\Message\Multipart\File;
use Buggregator\Client\Traffic\Message\Smtp\Contact;
use Buggregator\Client\Traffic\Message\Smtp\MessageFormat;
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

    /**
     * @param array<array-key, list<scalar>> $protocol
     * @param array<array-key, scalar|list<scalar>> $headers
     */
    private function __construct(
        private readonly array $protocol,
        array $headers,
    ) {
        $this->setHeaders($headers);
    }

    /**
     * @param array<array-key, list<scalar>> $protocol
     * @param array<array-key, scalar|list<scalar>> $headers
     */
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
     * @param Field[] $messages
     */
    public function withMessages(array $messages): self
    {
        $clone = clone $this;
        $clone->messages = $messages;
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

    /**
     * @return array<string, string|list<string>>
     */
    public function getProtocol(): array
    {
        return $this->protocol;
    }

    /**
     * @return Contact[]
     */
    public function getSender(): array
    {
        $addrs = \array_unique(\array_merge((array)($this->protocol['FROM'] ?? []), $this->getHeader('From')));

        return \array_map([$this, 'parseContact'], $addrs);
    }

    /**
     * @return Contact[]
     */
    public function getTo(): array
    {
        return \array_map([$this, 'parseContact'], $this->getHeader('To'));
    }

    /**
     * @return Contact[]
     */
    public function getCc(): array
    {
        return \array_map([$this, 'parseContact'], $this->getHeader('Cc'));
    }

    /**
     * BCCs are recipients passed as RCPTs but not
     * in the body of the mail.
     *
     * @return Contact[]
     */
    public function getBcc(): array
    {
        return \array_map([$this, 'parseContact'], $this->protocol['BCC'] ?? []);
    }

    /**
     * @return Contact[]
     */
    public function getReplyTo(): array
    {
        return \array_map([$this, 'parseContact'], $this->getHeader('Reply-To'));
    }

    public function getSubject(): string
    {
        return \implode(' ', $this->getHeader('Subject'));
    }

    public function getMessage(MessageFormat $type): ?Field
    {
        foreach ($this->messages as $message) {
            if (\stripos($message->getHeaderLine('Content-Type'), $type->contentType()) !== false) {
                return $message;
            }
        }

        return null;
    }

    private function parseContact(string $line): Contact {
        if (\preg_match('/^\s*(?<name>.*)\s*<(?<email>.*)>\s*$/', $line, $matches) !== 1) {
            return new Contact($matches['name'] ?: null, $matches['email'] ?: null);
        }

        return new Contact(null, $line);
    }
}
