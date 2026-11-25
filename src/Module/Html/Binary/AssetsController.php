<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html\Binary;

use Buggregator\Trap\Module\Frontend\Http\ContentDisposition;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Nyholm\Psr7\Response;

/**
 * @internal
 */
final class AssetsController
{
    /** The download of the bytes of an event: the path, then the UUID of the event. */
    public const PATH = '/api/binary/';

    public function __construct(
        private readonly EventStore $events,
    ) {}

    /**
     * All the bytes of a binary event, a download.
     *
     * @param non-empty-string $uuid
     */
    #[RegexpRoute(Method::Get, '#^api/binary/(?<uuid>[a-f0-9-]++)$#')]
    #[AssertSuccess(Method::Get, 'api/binary/0145a0e0-0b1a-4e4a-9b1a', ['uuid' => '0145a0e0-0b1a-4e4a-9b1a']),
        AssertFail(Method::Get, 'api/binary/'),
        AssertFail(Method::Post, 'api/binary/0145a0e0-0b1a-4e4a-9b1a'),]
    public function download(string $uuid): ?Response
    {
        $payload = $this->events->get($uuid)?->payload;
        if (!$payload instanceof Payload) {
            return null;
        }

        return new Response(
            200,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => ContentDisposition::attachment($payload->fileName()),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-cache',
            ],
            // The emitter reads it through a view of its own: the position stays for the other readers
            $payload->stream,
        );
    }
}
