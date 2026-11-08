<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder as XDebugProfileBuilder;
use Buggregator\Trap\Proto\Frame;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Receives Xdebug profiles: `POST /api/profiler/xdebug` with a cachegrind file as the body, gzipped or not.
 *
 * Optional query parameters: `app_name`, `hostname` and `tags[name]=value`.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class XDebugTrap implements Middleware
{
    /** Xdebug writes a block per call, so its profiles are larger than XHProf ones. */
    private const MAX_BODY_SIZE = 64 * 1024 * 1024;

    public function __construct(
        private readonly XDebugProfileBuilder $profileBuilder,
        private readonly Logger $logger,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if ($request->getMethod() !== 'POST' || !\str_ends_with($request->getUri()->getPath(), '/api/profiler/xdebug')) {
            return $next($request);
        }

        try {
            return $this->processStore($request);
        } catch (\Throwable $e) {
            $this->logger->exception($e, important: true);
            return new Response(400, body: $e->getMessage());
        }
    }

    private function processStore(ServerRequestInterface $request): ResponseInterface
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > self::MAX_BODY_SIZE) {
            return new Response(413);
        }

        $content = (string) $request->getBody();
        $query = $request->getQueryParams();
        $metadata = [];
        foreach (['app_name', 'hostname'] as $key) {
            /** @var mixed $value */
            $value = $query[$key] ?? null;
            \is_string($value) && $value !== '' and $metadata[$key] = $value;
        }
        $tags = [];
        /** @var mixed $rawTags */
        $rawTags = $query['tags'] ?? null;
        /** @var mixed $value */
        foreach (\is_array($rawTags) ? $rawTags : [] as $name => $value) {
            \is_string($name) && $name !== '' && \is_string($value) && $value !== '' and $tags[$name] = $value;
        }

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        // Parse before accepting, to reject what is not a profile
        $profile = $this->profileBuilder->createProfile($content, $time, $metadata, $tags);

        \Fiber::suspend(
            new Frame\Profiler(
                payload: Frame\Profiler\Payload::new(
                    type: Frame\Profiler\Type::XDebug,
                    callsProvider: static fn(): Profile => $profile,
                ),
                time: $time,
            ),
        );

        return new Response(200);
    }
}
