<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Http;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Handler\Router\Router as CommonRouter;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Sender\Frontend\Event\AttachedFile;
use Buggregator\Trap\Sender\Frontend\Event\EmbeddedFile;
use Buggregator\Trap\Sender\Frontend\EventStorage;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class EventAssets implements Middleware
{
    private readonly CommonRouter $router;

    public function __construct(
        private readonly Logger $logger,
        private readonly EventStorage $eventsStorage,
    ) {
        $this->router = CommonRouter::new($this);
    }

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = \trim($request->getUri()->getPath(), '/');
        $method = $request->getMethod();

        $handler = $this->router->match(Method::fromString($method), $path);

        if ($handler === null) {
            return $next($request);
        }

        /** @var ResponseInterface $response */
        $response = $handler() ?? new Response(404);

        return $response;
    }

    /**
     * @param non-empty-string $eventId
     */
    #[RegexpRoute(Method::Get, '#^api/smtp/(?<eventId>[a-f0-9-]++)/html#')]
    #[
        AssertSuccess(Method::Get, 'api/smtp/0145a0e0-0b1a-4e4a-9b1a/html', ['eventId' => '0145a0e0-0b1a-4e4a-9b1a']),
        AssertSuccess(Method::Get, 'api/smtp/0145a0e0-0b1a-4e4a-9b1a/html/', ['eventId' => '0145a0e0-0b1a-4e4a-9b1a']),
        AssertFail(Method::Get, 'api/smtp/foo-bar-baz/html')
    ]
    public function smtpHtml(string $eventId): ?Response
    {
        // Find event
        $event = $this->eventsStorage->get($eventId);

        if ($event === null) {
            $this->logger->debug('Get HTML for event `%s`. Event not found.', $eventId);
            return null;
        }

        /** @var string $html */
        $html = $event->payload['html'] ?? '';

        return new Response(
            200,
            [
                'Content-Type' => 'text/html',
                'Cache-Control' => 'no-cache',
            ],
            $html,
        );
    }

    /**
     * @param non-empty-string $eventId
     * @param non-empty-string $attachId
     */
    #[RegexpRoute(Method::Get, '#^api/smtp/(?<eventId>[a-f0-9-]++)/attachment/(?<attachId>[a-f0-9-]++)$#')]
    #[
        AssertSuccess(
            Method::Get,
            'api/smtp/0145a0e0-0b1a-4e4a-9b1a/attachment/0145a0e0-0b1a-4e4a-9b1a',
            ['eventId' => '0145a0e0-0b1a-4e4a-9b1a', 'attachId' => '0145a0e0-0b1a-4e4a-9b1a'],
        ),
        AssertFail(Method::Get, 'api/smtp/0145a0e0-0b1a-4e4a-9b1a/attachment/0145a0e0ZZZZzzzz')
    ]
    public function attachment(string $eventId, string $attachId): ?Response
    {
        // Find event
        $event = $this->eventsStorage->get($eventId);

        if ($event === null) {
            $this->logger->debug('Get attachment `%s` for event `%s`. Event not found.', $attachId, $eventId);
            return null;
        }

        // Find attachment
        $attachment = $event->assets[$attachId] ?? null;

        if ($attachment instanceof AttachedFile) {
            return new Response(
                200,
                [
                    'Content-Type' => $attachment->file->getClientMediaType(),
                    'Content-Disposition' => \sprintf(
                        "attachment; filename=\"%s\"",
                        \rawurlencode($attachment->file->getClientFilename() ?? 'unnamed'),
                    ),
                    'Content-Length' => (string) $attachment->file->getSize(),
                    'Cache-Control' => 'no-cache',
                ],
                $attachment->file->getStream(),
            );
        }

        if ($attachment instanceof EmbeddedFile) {
            return new Response(
                200,
                [
                    'Content-Type' => $attachment->file->getClientMediaType(),
                    'Content-Length' => (string) $attachment->file->getSize(),
                    'Cache-Control' => 'no-cache',
                ],
                $attachment->file->getStream(),
            );
        }

        $this->logger->debug('Get attachment `%s` for event `%s`. Attached file not found.', $attachId, $eventId);
        return null;
    }
}
