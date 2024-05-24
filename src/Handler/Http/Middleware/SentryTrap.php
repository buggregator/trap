<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Handler\Http\Middleware\SentryTrap\EnvelopeParser;
use Buggregator\Trap\Proto\Frame;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 *
 * @psalm-import-type SentryStoreMessage from Frame\Sentry\SentryStore
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

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        $frame = EnvelopeParser::parse($request->getBody(), $time);
        \Fiber::suspend($frame);

        return new Response(200);
    }

    private function processStore(ServerRequestInterface $request): ResponseInterface
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > self::MAX_BODY_SIZE) {
            // Reject too big content
            return new Response(413);
        }
        /** @var SentryStoreMessage $payload */
        $payload = \json_decode((string) $request->getBody(), true, 96, \JSON_THROW_ON_ERROR);

        /** @psalm-suppress MixedAssignment */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        \Fiber::suspend(
            new Frame\Sentry\SentryStore(
                message: $payload,
                time: $time,
            ),
        );

        return new Response(200);
    }
}
