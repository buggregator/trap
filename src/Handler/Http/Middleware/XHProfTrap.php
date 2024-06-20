<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder as XHProfProfileBuilder;
use Buggregator\Trap\Proto\Frame;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 *
 * @psalm-type XHProfMessage = array{
 *     profile: array,
 *     tags: array,
 *     app_name: string,
 *     hostname: string,
 *     date: positive-int
 * }
 */
final class XHProfTrap implements Middleware
{
    private const MAX_BODY_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private readonly XHProfProfileBuilder $profileBuilder,
        private readonly Logger $logger,
    ) {}

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        try {
            if ($request->getMethod() === 'POST'
                && \str_ends_with($request->getUri()->getPath(), '/api/profiler/store')
            ) {
                return $this->processStore($request);
            }
        } catch (\JsonException $e) {
            // Reject invalid JSON
            $this->logger->exception($e, important: true);
            return new Response(400, body: 'Invalid JSON data.');
        } catch (\Throwable $e) {
            // Reject invalid request
            $this->logger->exception($e, important: true);
            return new Response(400, body: $e->getMessage());
        }

        return $next($request);
    }

    /**
     * @throws \JsonException
     * @throws \Throwable
     */
    private function processStore(ServerRequestInterface $request): ResponseInterface
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > self::MAX_BODY_SIZE) {
            // Reject too big content
            return new Response(413);
        }

        /** @var XHProfMessage $payload */
        $payload = \json_decode((string) $request->getBody(), true, 96, \JSON_THROW_ON_ERROR);

        \is_array($payload['profile'] ?? null) && \is_array($payload['tags'] ?? null)
        or throw new \InvalidArgumentException('Invalid payload');

        $metadata = $payload;
        unset($metadata['profile'], $metadata['tags'], $metadata['date']);

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        /** @psalm-suppress MixedArgumentTypeCoercion */
        \Fiber::suspend(
            new Frame\Profiler(
                payload: Frame\Profiler\Payload::new(
                    type: Frame\Profiler\Type::XHProf,
                    callsProvider: fn(): Profile => $this->profileBuilder->createProfile(
                        date: $time,
                        metadata: $metadata,
                        tags: $payload['tags'] ?? [],
                        calls: $payload['profile'] ?? [],
                    ),
                ),
                time: $time,
            ),
        );

        return new Response(200);
    }
}
