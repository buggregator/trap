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
            $response = $own || $this->startsWithSchema($request) ? $this->processStore($request, $own) : null;
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
     * Other modules' JSON requests pass here too: only a body that starts with the speedscope `$schema`,
     * as speedscope, Excimer and `json_encode()` write it, is decoded. Other speedscope documents go to
     * {@see self::PATH}.
     */
    private function startsWithSchema(ServerRequestInterface $request): bool
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > $this->profileBuilder->maxSize
            || !\str_contains($request->getHeaderLine('Content-Type'), 'json')
        ) {
            return false;
        }

        $body = $request->getBody();
        $body->rewind();
        $head = $body->read(256);
        $body->rewind();
        // `json_encode()` escapes the slashes of the URL by default
        return \preg_match(
            '~^\s*\{\s*"\$schema"\s*:\s*"https:(?:\\\\?/){2}www\.speedscope\.app\\\\?/file-format-schema\.json"~',
            $head,
        ) === 1;
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
     * @param bool $own The request came to {@see self::PATH}: what isn't a speedscope profile is rejected.
     *
     * @return ResponseInterface|null Null when the request is not a speedscope one.
     */
    private function processStore(ServerRequestInterface $request, bool $own): ?ResponseInterface
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

        // Built before accepting, to reject what is not a profile. The decoding takes memory too, so one at a time;
        // the builder gets the only reference to the document, to free it while building,
        // and lets the other fibers run meanwhile.
        $profile = $this->lock->run(function () use ($request, $own, $time, $metadata, $tags, $name, $clock, $period) {
            $document = $own ? $this->profileBuilder->decode((string) $request->getBody()) : $this->detect($request);
            return $document === null ? null : $this->profileBuilder->createProfile(
                self::take($document) ?? [],
                $time,
                $metadata,
                $tags,
                $name,
                cpuClock: $clock === 'cpu',
                period: $period,
                timeSlice: new TimeSlice(),
            );
        });
        if ($profile === null) {
            return null;
        }

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
