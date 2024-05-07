<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Mapper;

use ArrayAccess;
use ArrayObject;
use Buggregator\Trap\Proto\Frame\Smtp as SmtpFrame;
use Buggregator\Trap\Sender\Frontend\Event;
use Buggregator\Trap\Support\Uuid;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use Buggregator\Trap\Traffic\Message\Smtp\MessageFormat;

/**
 * @internal
 */
final class Smtp
{
    public function map(SmtpFrame $frame): Event
    {
        $message = $frame->message;

        /** @var ArrayAccess<non-empty-string, Event\Asset> $assets */
        $assets = new ArrayObject();

        return new Event(
            uuid: $uuid = Uuid::generate(),
            type: 'smtp',
            payload: [
                'from' => $message->getSender(),
                'reply_to' => $message->getReplyTo(),
                'subject' => $message->getSubject(),
                'to' => $message->getTo(),
                'cc' => $message->getCc(),
                'bcc' => $message->getBcc(),
                'text' => $message->getMessage(MessageFormat::Plain)?->getValue() ?? '',
                'html' => $message->getMessage(MessageFormat::Html)?->getValue() ?? '',
                'raw' => (string) $message->getBody(),
                'attachments' => \array_map(
                    static function (File $attachment) use ($assets, $uuid): array {
                        $asset = new Event\AttachedFile(
                            id: Uuid::generate(),
                            file: $attachment,
                        );
                        $uri = $uuid . '/' . $asset->uuid;
                        $assets->offsetSet($asset->uuid, $asset);

                        return [
                            'id' => $asset->uuid,
                            'name' => $attachment->getClientFilename(),
                            'uri' => $uri,
                            'size' => $attachment->getSize(),
                            'mime' => $attachment->getClientMediaType(),
                        ];
                    },
                    $message->getAttachments(),
                ),
            ],
            timestamp: (float) $frame->time->format('U.u'),
            assets: $assets,
        );
    }
}
