<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\BuildQueueFull;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Pprof\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Support\TimeSlice;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Receives pprof profiles: `POST /api/profiler/pprof` with the protobuf, gzip-compressed or not.
 *
 * Optional query parameters: `app_name`, `hostname`, `name` and `tags[name]=value`. The sampling period,
 * the clock and the units come with the profile.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class PprofTrap implements Middleware
{
    private const PATH = '/api/profiler/pprof';

    public function __construct(
        private readonly ProfileBuilder $profileBuilder,
        private readonly Logger $logger,
        private readonly BuildLock $lock,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if ($request->getMethod() !== 'POST' || !\str_ends_with($request->getUri()->getPath(), self::PATH)) {
            return $next($request);
        }

        try {
            return $this->processStore($request);
        } catch (\InvalidArgumentException $e) {
            return $this->reject(400, $e->getMessage());
        } catch (BuildQueueFull $e) {
            return $this->reject(503, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->exception($e, important: true);
            return new Response(400, body: $e->getMessage());
        }
    }

    private function processStore(ServerRequestInterface $request): ResponseInterface
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > $this->profileBuilder->maxSize) {
            return $this->reject(413, 'The body is too large.');
        }

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
        /** @var mixed $name */
        $name = $query['name'] ?? null;
        $name = \is_string($name) && $name !== '' ? $name : null;

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        // Built before accepting, to reject what is not a profile. The decoding takes memory too, so one at a time,
        // letting the other fibers run meanwhile.
        $profile = $this->lock->run(fn(): Profile => $this->profileBuilder->createProfile(
            $this->profileBuilder->decode((string) $request->getBody()),
            $time,
            $metadata,
            $tags,
            $name,
            timeSlice: new TimeSlice(),
        ));

        /** @var mixed $compacted */
        $compacted = $profile->metadata['compacted'] ?? null;
        \is_array($compacted) and $this->logger->warning(
            'Pprof: the profile is compacted to %d call tree nodes: %s.',
            (int) ($compacted['limit'] ?? 0),
            Report::summarize($compacted),
        );

        \Fiber::suspend(
            new Frame(
                payload: Frame\Payload::new(
                    type: Frame\Type::Pprof,
                    callsProvider: static fn(): Profile => $profile,
                ),
                time: $time,
            ),
        );

        return new Response(200);
    }

    private function reject(int $status, string $reason): ResponseInterface
    {
        $this->logger->warning('Pprof: profile rejected with %d: %s', $status, \lcfirst($reason));

        return new Response($status, body: $reason);
    }
}
