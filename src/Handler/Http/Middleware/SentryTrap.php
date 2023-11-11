<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Handler\Http\Middleware\SentryTrap\EnvelopeParser;
use Buggregator\Trap\Proto\Frame;
use Fiber;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class SentryTrap implements Middleware
{
    private const MAX_BODY_SIZE = 2 * 1024 * 1024;

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        try {
            // Detect Sentry envelope
            if ($request->getHeaderLine('Content-Type') === 'application/x-sentry-envelope'
                && \str_ends_with($request->getUri()->getPath(), '/envelope/')
            ) {
                return $this->processEnvelope($request);
            }

            if (\str_ends_with($request->getUri()->getPath(), '/store/')
                && (
                    $request->getHeaderLine('X-Buggregator-Event') === 'sentry'
                    || $request->hasHeader('X-Sentry-Auth')
                    || $request->getUri()->getUserInfo() === 'sentry'
                )
            ) {
                return $this->processStore($request);
            }
        } catch (\JsonException) {
            // Reject invalid JSON
            return new Response(400);
        } catch (\Throwable) {
            // Reject invalid request
            return new Response(400);
        }

        return $next($request);
    }

    /**
     * @param ServerRequestInterface $request
     * @return Response
     * @throws \Throwable
     */
    public function processEnvelope(ServerRequestInterface $request): ResponseInterface
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > self::MAX_BODY_SIZE) {
            // Reject too big envelope
            return new Response(413);
        }

        $request->getBody()->rewind();
        $frame = EnvelopeParser::parse($request->getBody(), $request->getAttribute('begin_at', null));
        Fiber::suspend($frame);

        return new Response(200);
    }

    private function processStore(ServerRequestInterface $request): ResponseInterface
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > self::MAX_BODY_SIZE) {
            // Reject too big content
            return new Response(413);
        }

        $payload = \json_decode((string)$request->getBody(), true, 96, \JSON_THROW_ON_ERROR);

        Fiber::suspend(
            new Frame\SentryStore(
                message: $payload,
                time: $request->getAttribute('begin_at', null),
            )
        );

        return new Response(200);
    }
}
