<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\HttpDump\Frontend;

use Buggregator\Trap\Module\HttpDump\Frame as HttpFrame;
use Buggregator\Trap\Server\Event\Asset;
use Buggregator\Trap\Server\Event\AttachedFile;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Event\Facets;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Uuid;
use Psr\Http\Message\UploadedFileInterface;

/**
 * @internal
 */
final class Mapper implements EventMapper
{
    public const TYPE = 'http-dump';

    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof HttpFrame);

        $request = $frame->request;

        $uri = \ltrim($request->getUri()->getPath(), '/');
        /** @var \ArrayObject<non-empty-string, Asset> $assets */
        $assets = new \ArrayObject();

        return new Event(
            uuid: $uuid = Uuid::generate(),
            type: self::TYPE,
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
                            $asset = new AttachedFile(
                                id: Uuid::generate(),
                                file: $attachment,
                            );
                            $uri = "/api/http-dump/attachments/{$uuid}/{$asset->uuid}";
                            $assets->offsetSet($asset->uuid, $asset);

                            return [
                                'uuid' => $asset->uuid,
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
            facets: (new Facets())
                ->add(Facets::HOST, \preg_replace('/:\d*$/', '', $request->getHeaderLine('Host')))
                ->add(Facets::TRACE, Facets::traceFromHeaders($request->getHeaderLine(...)))
                ->toArray(),
        );
    }
}
