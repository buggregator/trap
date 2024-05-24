<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Message;

use Buggregator\Trap\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use Buggregator\Trap\Traffic\Message\Smtp\Contact;
use Buggregator\Trap\Traffic\Message\Smtp\MessageFormat;
use Psr\Http\Message\StreamInterface;

/**
 * @method StreamInterface getBody() Get full raw message body
 *
 * @psalm-import-type FieldDataArray from Field
 * @psalm-import-type FileDataArray from File
 *
 * @psalm-type TArrayData = array{
 *      protocol: array<non-empty-string, list<string>>,
 *      headers: array<array-key, scalar|non-empty-list<non-empty-string>>,
 *      messages: list<FieldDataArray>,
 *      attachments: list<FileDataArray>,
 *  }
 *
 * @internal
 */
final class Smtp implements \JsonSerializable
{
    use Headers;
    use StreamBody;

    /** @var list<Field> */
    private array $messages = [];

    /** @var list<File> */
    private array $attachments = [];

    /**
     * @param array<non-empty-string, list<string>> $protocol
     * @param array<array-key, scalar|list<non-empty-string>> $headers
     */
    private function __construct(
        private readonly array $protocol,
        array $headers,
    ) {
        $this->setHeaders($headers);
    }

    /**
     * @param array<non-empty-string, list<string>> $protocol
     * @param array<array-key, scalar|list<non-empty-string>> $headers
     */
    public static function create(array $protocol, array $headers): self
    {
        return new self($protocol, $headers);
    }

    /**
     * @param TArrayData $data
     */
    public static function fromArray(array $data): self
    {
        $self = new self($data['protocol'], $data['headers']);
        foreach ($data['messages'] as $message) {
            $self->messages[] = Field::fromArray($message);
        }
        foreach ($data['attachments'] as $attach) {
            $self->attachments[] = File::fromArray($attach);
        }

        return $self;
    }

    public function jsonSerialize(): array
    {
        return [
            'protocol' => $this->protocol,
            'headers' => $this->headers,
            'messages' => $this->messages,
            'attachments' => $this->attachments,
        ];
    }

    /**
     * @return list<Field>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return list<File>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * @param list<Field> $messages
     */
    public function withMessages(array $messages): self
    {
        $clone = clone $this;
        $clone->messages = $messages;
        return $clone;
    }

    /**
     * @param list<File> $attachments
     */
    public function withAttachments(array $attachments): self
    {
        $clone = clone $this;
        $clone->attachments = $attachments;
        return $clone;
    }

    /**
     * @return array<non-empty-string, list<string>>
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
        $addrs = \array_unique(\array_merge((array) ($this->protocol['FROM'] ?? []), $this->getHeader('From')));

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

    private function parseContact(string $line): Contact
    {
        if (\preg_match('/^\s*(?<name>.*)\s*<(?<email>.*)>\s*$/', $line, $matches) === 1) {
            return new Contact($matches['name'] ?: null, $matches['email'] ?: null);
        }

        return new Contact(null, $line);
    }
}
