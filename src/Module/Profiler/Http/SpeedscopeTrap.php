<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\BuildQueueFull;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Speedscope\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Support\TimeSlice;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Receives speedscope profiles, such as the ones Excimer makes: `POST /api/profiler/speedscope` with the JSON document.
 *
 * Optional query parameters: `app_name`, `hostname`, `name`, `clock=wall|cpu`, `period` (the sampling period
 * in seconds, to count the timer events of a sample) and `tags[name]=value`.
 * A JSON request to another path is taken when its `$schema` is the speedscope one.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class SpeedscopeTrap implements Middleware
{
    private const PATH = '/api/profiler/speedscope';

    public function __construct(
        private readonly ProfileBuilder $profileBuilder,
        private readonly Logger $logger,
        private readonly BuildLock $lock,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $next($request);
        }

        try {
            $own = \str_ends_with($request->getUri()->getPath(), self::PATH);
            // The decoding takes memory too: it waits for the other builds
            $response = $own || $this->mentionsSchema($request) ? $this->lock->run(function () use ($request, $own) {
                $document = $own ? null : $this->detect($request);
                return $own || $document !== null ? $this->processStore($request, self::take($document)) : null;
            }) : null;
            if ($response !== null) {
                return $response;
            }
        } catch (\InvalidArgumentException $e) {
            return $this->reject(400, $e->getMessage());
        } catch (BuildQueueFull $e) {
            return $this->reject(503, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->exception($e, important: true);
            return new Response(400, body: $e->getMessage());
        }

        // Outside the `try`: errors of the later middlewares are not speedscope rejections.
        return $next($request);
    }

    /**
     * Moves the value out of the variable: what the variable held is then referenced only by the result.
     */
    private static function take(?array &$value): ?array
    {
        $result = $value;
        $value = null;

        return $result;
    }

    /**
     * Other modules' JSON requests pass here too: only a body that mentions the schema is decoded.
     */
    private function mentionsSchema(ServerRequestInterface $request): bool
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > $this->profileBuilder->maxSize
            || !\str_contains($request->getHeaderLine('Content-Type'), 'json')
        ) {
            return false;
        }

        $content = (string) $request->getBody();
        // `json_encode()` escapes the slashes of the URL by default
        return \str_contains($content, 'file-format-schema.json') && \str_contains($content, 'speedscope.app');
    }

    /**
     * @return array|null The document, or null when the request is not a speedscope one.
     */
    private function detect(ServerRequestInterface $request): ?array
    {
        try {
            $document = $this->profileBuilder->decode((string) $request->getBody());
        } catch (\InvalidArgumentException) {
            return null;
        }

        return ($document['$schema'] ?? null) === ProfileBuilder::SCHEMA ? $document : null;
    }

    /**
     * @param array|null $document Already decoded document, if any.
     */
    private function processStore(ServerRequestInterface $request, ?array $document): ResponseInterface
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
        /** @var mixed $clock */
        $clock = $query['clock'] ?? 'wall';
        $clock === 'wall' || $clock === 'cpu'
            or throw new \InvalidArgumentException('The `clock` parameter is neither `wall` nor `cpu`.');
        /** @var mixed $period */
        $period = $query['period'] ?? null;
        if ($period !== null) {
            \is_string($period) && \is_numeric($period) && (float) $period > 0.0 && \is_finite((float) $period)
                or throw new \InvalidArgumentException('The `period` parameter is not a positive number of seconds.');
            $period = (float) $period;
        }

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        // Built before accepting, to reject what is not a profile. The builder gets the only reference
        // to the document, to free it while building, and lets the other fibers run meanwhile.
        $profile = $this->profileBuilder->createProfile(
            self::take($document) ?? $this->profileBuilder->decode((string) $request->getBody()),
            $time,
            $metadata,
            $tags,
            $name,
            cpuClock: $clock === 'cpu',
            period: $period,
            timeSlice: new TimeSlice(),
        );

        /** @var mixed $compacted */
        $compacted = $profile->metadata['compacted'] ?? null;
        \is_array($compacted) and $this->logger->warning(
            'Speedscope: the profile is compacted to %d call tree nodes: %s.',
            (int) ($compacted['limit'] ?? 0),
            Report::summarize($compacted),
        );

        \Fiber::suspend(
            new Frame(
                payload: Frame\Payload::new(
                    type: Frame\Type::Speedscope,
                    callsProvider: static fn(): Profile => $profile,
                ),
                time: $time,
            ),
        );

        return new Response(200);
    }

    private function reject(int $status, string $reason): ResponseInterface
    {
        $this->logger->warning('Speedscope: profile rejected with %d: %s', $status, \lcfirst($reason));

        return new Response($status, body: $reason);
    }
}
