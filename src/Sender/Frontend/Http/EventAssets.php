<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Http;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Sender\Frontend\Event\AttachedFile;
use Buggregator\Trap\Sender\Frontend\EventsStorage;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class EventAssets implements Middleware
{
    public function __construct(
        private readonly Logger $logger,
        private readonly EventsStorage $eventsStorage,
    ) {
    }

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (\preg_match('#^/api/smtp/([^/]++)/attachment/([^/]++)#', $path, $matches) !== 1) {
            return $next($request);
        }


        // Find event
        $event = $this->eventsStorage->get($matches[1]);
        if ($event === null) {
            $this->logger->debug('Get attachment %s for event %s. Event not found.', $matches[2], $matches[1]);
            return new Response(404);
        }

        // Find attachment
        $attachment = $event->assets[$matches[2]] ?? null;

        if (!$attachment instanceof AttachedFile) {
            $this->logger->debug('Get attachment %s for event %s. Attached file not found.', $matches[2], $matches[1]);
            return new Response(404);
        }

        return new Response(
            200,
            [
                'Content-Type' => $attachment->file->getClientMediaType(),
                'Content-Disposition' => \sprintf(
                    "attachment; filename=\"%s\"",
                    \rawurlencode($attachment->file->getClientFilename()),
                ),
                'Content-Length' => (string)$attachment->file->getSize(),
                'Cache-Control' => 'no-cache',
            ],
            $attachment->file->getStream(),
        );
    }
}
