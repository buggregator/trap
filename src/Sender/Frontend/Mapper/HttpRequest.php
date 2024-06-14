<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Mapper;

use Buggregator\Trap\Proto\Frame\Http as HttpFrame;
use Buggregator\Trap\Sender\Frontend\Event;
use Buggregator\Trap\Support\Uuid;
use Psr\Http\Message\UploadedFileInterface;

/**
 * @internal
 */
final class HttpRequest
{
    public function map(HttpFrame $frame): Event
    {
        $request = $frame->request;

        $uri = \ltrim($request->getUri()->getPath(), '/');
        /** @var \ArrayObject<non-empty-string, Event\Asset> $assets */
        $assets = new \ArrayObject();

        return new Event(
            uuid: $uuid = Uuid::generate(),
            type: 'http-dump',
            payload: [
                'received_at' => $frame->time->format('Y-m-d H:i:s'),
                'host' => $request->getHeaderLine('Host'),
                'request' => [
                    'method' => $request->getMethod(),
                    'uri' => $uri,
                    'headers' => $request->getHeaders(),
                    'body' => $request->getParsedBody() === null
                        ? (string) $request->getBody()
                        : '',
                    'query' => $request->getQueryParams(),
                    'post' => $request->getParsedBody() ?? [],
                    'cookies' => $request->getCookieParams(),
                    'files' => \array_map(
                        static function (UploadedFileInterface $attachment) use ($assets, $uuid): array {
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
                        \iterator_to_array($frame->iterateUploadedFiles(), false),
                    ),
                ],
            ],
            timestamp: (float) $frame->time->format('U.u'),
            assets: $assets,
        );
    }
}
