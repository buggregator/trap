<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer\Frontend;

use Buggregator\Trap\Module\Mailer\Frame as SmtpFrame;
use Buggregator\Trap\Module\Mailer\Message as SmtpMessage;
use Buggregator\Trap\Module\Mailer\MessageFormat;
use Buggregator\Trap\Server\Event\Asset;
use Buggregator\Trap\Server\Event\AttachedFile;
use Buggregator\Trap\Server\Event\EmbeddedFile;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class Mapper implements EventMapper
{
    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof SmtpFrame);

        $message = $frame->message;

        $uuid = Uuid::generate();
        $assets = self::fetchAssets($message);

        return new Event(
            uuid: $uuid,
            type: 'smtp',
            payload: [
                'from' => $message->getSender(),
                'reply_to' => $message->getReplyTo(),
                'subject' => $message->getSubject(),
                'to' => $message->getTo(),
                'cc' => $message->getCc(),
                'bcc' => $message->getBcc(),
                'text' => $message->getMessage(MessageFormat::Plain)?->getValue() ?? '',
                'html' => self::html($uuid, $message, $assets),
                'raw' => (string) $message->getBody(),
                'auth' => self::auth($message),
            ],
            timestamp: (float) $frame->time->format('U.u'),
            assets: $assets,
        );
    }

    /**
     * @return array{mechanism: string, username: string, password: string, authzid: string|null}|null Null when
     *         the client sent the mail without `AUTH`; `authzid` is the user a PLAIN client acts for, if another.
     */
    private static function auth(SmtpMessage $message): ?array
    {
        $protocol = $message->getProtocol();

        return isset($protocol['AUTH'][0]) ? [
            'mechanism' => $protocol['AUTH'][0],
            'username' => $protocol['USERNAME'][0] ?? '',
            'password' => $protocol['PASSWORD'][0] ?? '',
            'authzid' => $protocol['AUTHZID'][0] ?? null,
        ] : null;
    }

    /**
     * @param non-empty-string $uuid UUID of the event
     */
    private static function assetLink(string $uuid, Asset $asset): string
    {
        return "/api/smtp/$uuid/attachment/$asset->uuid";
    }

    /**
     * @return \ArrayObject<non-empty-string, Asset>
     */
    private static function fetchAssets(SmtpMessage $message): \ArrayObject
    {
        /** @var \ArrayObject<non-empty-string, Asset> $assets */
        $assets = new \ArrayObject();

        $html = $message->getMessage(MessageFormat::Html)?->getValue() ?? '';
        foreach ($message->getAttachments() as $attachment) {
            $asset = self::asset($attachment, $html);
            $assets->offsetSet($asset->uuid, $asset);
        }

        return $assets;
    }

    /**
     * The UI lists attached files only and shows an embedded one in the HTML,
     * so a file the HTML doesn't refer to is listed as attached.
     */
    private static function asset(File $attachment, string $html): Asset
    {
        $embedded = $attachment->getEmbeddingId();
        $embedded === null || \str_contains($html, "cid:{$embedded}") or $embedded = null;
        return $embedded === null
            ? new AttachedFile(
                id: Uuid::generate(),
                file: $attachment,
            )
            : new EmbeddedFile(
                id: Uuid::generate(),
                file: $attachment,
                name: $embedded,
            );
    }

    /**
     * @param non-empty-string $uuid
     * @param \ArrayAccess<non-empty-string, Asset>&\Traversable<non-empty-string, Asset> $assets
     */
    private static function html(string $uuid, SmtpMessage $message, \ArrayAccess&\Traversable $assets): string
    {
        $result = $message->getMessage(MessageFormat::Html)?->getValue() ?? '';

        // Replace CID links with actual asset links
        $toReplace = [];
        foreach ($assets as $asset) {
            $asset instanceof EmbeddedFile and $toReplace["cid:{$asset->name}"] = self::assetLink($uuid, $asset);
        }

        return \str_replace(\array_keys($toReplace), \array_values($toReplace), $result);
    }
}
