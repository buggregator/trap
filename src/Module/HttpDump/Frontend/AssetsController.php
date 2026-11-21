<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\HttpDump\Frontend;

use Buggregator\Trap\Module\Frontend\Http\ContentDisposition;
use Buggregator\Trap\Server\Event\AttachedFile;
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
    public function __construct(
        private readonly EventStore $events,
    ) {}

    /**
     * A file uploaded with the dumped request.
     *
     * @param non-empty-string $eventId
     * @param non-empty-string $fileId
     */
    #[RegexpRoute(Method::Get, '#^api/http-dump/attachments/(?<eventId>[a-f0-9-]++)/(?<fileId>[a-f0-9-]++)$#')]
    #[AssertSuccess(
        Method::Get,
        'api/http-dump/attachments/0145a0e0-0b1a-4e4a-9b1a/0145a0e0-0b1a-4e4a-9b1b',
        ['eventId' => '0145a0e0-0b1a-4e4a-9b1a', 'fileId' => '0145a0e0-0b1a-4e4a-9b1b'],
    ),
        AssertFail(Method::Get, 'api/http-dump/attachments/0145a0e0-0b1a-4e4a-9b1a'),]
    public function file(string $eventId, string $fileId): ?Response
    {
        $file = $this->events->get($eventId)?->assets[$fileId] ?? null;
        if (!$file instanceof AttachedFile) {
            return null;
        }

        return new Response(
            200,
            [
                'Content-Type' => $file->file->getClientMediaType() ?? 'application/octet-stream',
                'Content-Disposition' => ContentDisposition::attachment($file->file->getClientFilename()),
                'Cache-Control' => 'no-cache',
            ],
            (string) $file->file->getStream(),
        );
    }
}
