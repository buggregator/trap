<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer;

use Buggregator\Trap\Server\Traffic\Message\Headers;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Buggregator\Trap\Server\Traffic\Message\StreamBody;
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
final class Message implements \JsonSerializable
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

    #[\Override]
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
     * The envelope sender and the `From` header, one contact per address: a named one wins.
     *
     * @return list<Contact>
     */
    public function getSender(): array
    {
        /** @var array<non-empty-string, Contact> $result */
        $result = [];
        $contacts = \array_merge(
            self::normalizeAddressList($this->protocol['FROM'] ?? []),
            self::normalizeAddressList($this->getHeader('From')),
        );
        foreach ($contacts as $contact) {
            $key = $contact->email === null ? "name:{$contact->name}" : 'email:' . \strtolower($contact->email);
            if (!isset($result[$key]) || ($result[$key]->name === null && $contact->name !== null)) {
                $result[$key] = $contact;
            }
        }

        return \array_values($result);
    }

    /**
     * @return Contact[]
     */
    public function getTo(): array
    {
        return self::normalizeAddressList($this->getHeader('To'));
    }

    /**
     * @return Contact[]
     */
    public function getCc(): array
    {
        return self::normalizeAddressList($this->getHeader('Cc'));
    }

    /**
     * BCCs are recipients passed as RCPTs but not
     * in the body of the mail.
     *
     * @return Contact[]
     */
    public function getBcc(): array
    {
        return self::normalizeAddressList($this->protocol['BCC'] ?? []);
    }

    /**
     * @return Contact[]
     */
    public function getReplyTo(): array
    {
        return self::normalizeAddressList($this->getHeader('Reply-To'));
    }

    public function getSubject(): string
    {
        return \implode(' ', \array_map(EncodedWord::decode(...), $this->getHeader('Subject')));
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

    /**
     * @param string $address One address: `email`, `name <email>`, `"name" <email>` or `email (name)`.
     */
    private static function parseContact(string $address): ?Contact
    {
        if (\preg_match('/^(?<name>.*?)\s*<(?<email>[^<>]*)>$/s', $address, $matches) === 1) {
            $name = $matches['name'];
            $email = \trim($matches['email']);
        } elseif (\preg_match('/^(?<email>[^\s()]*)\s*\((?<name>.*)\)$/s', $address, $matches) === 1) {
            $name = $matches['name'];
            $email = $matches['email'];
        } else {
            $name = '';
            $email = $address;
        }

        if (\preg_match('/^"(.*)"$/s', $name, $matches) === 1) {
            $name = (string) \preg_replace('/\\\\(.)/s', '$1', $matches[1]);
        }

        // Decoded after the split: a decoded name may hold a comma or a quote.
        $name = $name === '' ? null : EncodedWord::decode($name);
        $email = $email === '' ? null : $email;

        return $name === null && $email === null ? null : new Contact($name, $email);
    }

    /**
     * Splits an address list at the commas outside quoted strings, comments, angle and square brackets.
     * A group `name: a, b;` gives its members.
     *
     * @return list<non-empty-string>
     */
    private static function splitAddressList(string $line): array
    {
        $result = [];
        $current = '';
        $quoted = $angle = $literal = false;
        $comment = 0;
        $length = \strlen($line);
        for ($i = 0; $i < $length; ++$i) {
            $char = $line[$i];
            if ($char === '\\' && ($quoted || $comment > 0) && $i + 1 < $length) {
                $current .= $char . $line[++$i];
                continue;
            }

            if ($quoted) {
                $quoted = $char !== '"';
            } elseif ($comment > 0) {
                $char === '(' and ++$comment;
                $char === ')' and --$comment;
            } elseif ($char === '"') {
                $quoted = true;
            } elseif ($char === '(') {
                $comment = 1;
            } elseif ($char === '<' || $char === '>') {
                $angle = $char === '<';
            } elseif ($char === '[' || $char === ']') {
                // A domain literal, such as `[IPv6:::1]`.
                $literal = $char === '[';
            } elseif (!$angle && !$literal && \in_array($char, [',', ':', ';'], true)) {
                // The name of a group is not an address.
                $char === ':' or $result[] = \trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }
        $result[] = \trim($current);

        return \array_values(\array_filter($result, static fn(string $address): bool => $address !== ''));
    }

    /**
     * @param list<string> $lines Header values or envelope addresses.
     * @return list<Contact>
     */
    private static function normalizeAddressList(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            foreach (self::splitAddressList($line) as $address) {
                $contact = self::parseContact($address);
                $contact === null or $result[] = $contact;
            }
        }

        return $result;
    }
}
