<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Smtp;

use Buggregator\Client\Traffic\Multipart\Field;
use Buggregator\Client\Traffic\Multipart\File;
use Buggregator\Client\Traffic\Multipart\Headers;
use Buggregator\Client\Traffic\Multipart\StreamBody;
use JsonSerializable;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

final class Message implements JsonSerializable
{
    private ?StreamInterface $stream = null;

    use Headers;
    use StreamBody;

    /** @var Field[] */
    private array $texts = [];

    /** @var File[] */
    private array $attaches = [];

    private function __construct(array $headers)
    {
        $this->setHeaders($headers);
    }

    public static function create(array $headers): self
    {
        return new self($headers);
    }

    /**
     * @return Field[]
     */
    public function getTexts(): array
    {
        return $this->texts;
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
        $clone->texts = $texts;
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
     * Get full raw message body
     */
    public function getBody(): StreamInterface
    {
        if (null === $this->stream) {
            $this->stream = Stream::create('');
        }

        return $this->stream;
    }

    public function withBody(StreamInterface $body): self
    {
        if ($body === $this->stream) {
            return $this;
        }

        $new = clone $this;
        $new->stream = $body;

        return $new;
    }


    /**
     * BCCs are recipients passed as RCPTs but not
     * in the body of the mail.
     *
     * @return non-empty-string[]
     */
    private function getBccs(): array
    {
        return \array_values(
            \array_filter($this->allRecipients, function (string $recipient) {
                foreach (\array_merge($this->recipients, $this->ccs) as $publicRecipient) {
                    if (\str_contains($publicRecipient, $recipient)) {
                        return false;
                    }
                }

                return true;
            }),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'from' => $this->sender,
            'reply_to' => $this->replyTo,
            'subject' => $this->subject,
            'to' => $this->recipients,
            'cc' => $this->ccs,
            'bcc' => $this->getBccs(),
            'text' => $this->textBody,
            'html' => $this->htmlBody,
            'raw' => $this->raw,
            'attachments' => $this->attachments,
        ];
    }
}
