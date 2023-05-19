<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Smtp;

use JsonSerializable;

final class Message implements JsonSerializable
{
    /**
     * @param Attachment[] $attachments
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $raw,
        public readonly array $sender,
        public readonly array $recipients,
        public readonly array $ccs,
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly string $textBody,
        public readonly array $replyTo,
        public readonly array $allRecipients,
        public readonly array $attachments,
    ) {
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
