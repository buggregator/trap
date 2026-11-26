<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\BuildQueueFull;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Stacks\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Support\TimeSlice;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Receives stack samples as text, gzip-compressed or not: `POST /api/profiler/phpspy` with a phpspy or reli-prof
 * trace, `POST /api/profiler/collapsed` with collapsed stacks.
 *
 * Optional query parameters: `app_name`, `hostname`, `name`, `period` (the sampling period in seconds,
 * to measure the time) and `tags[name]=value`; collapsed stacks also take `clock=wall|cpu`.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class StacksTrap implements Middleware
{
    private const PATHS = [
        '/api/profiler/phpspy' => Frame\Type::Phpspy,
        '/api/profiler/collapsed' => Frame\Type::Collapsed,
    ];

    public function __construct(
        private readonly ProfileBuilder $profileBuilder,
        private readonly Logger $logger,
        private readonly BuildLock $lock,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $type = $request->getMethod() === 'POST' ? self::type($request->getUri()->getPath()) : null;
        if ($type === null) {
            return $next($request);
        }

        try {
            return $this->processStore($request, $type);
        } catch (\InvalidArgumentException $e) {
            return $this->reject($type, 400, $e->getMessage());
        } catch (BuildQueueFull $e) {
            return $this->reject($type, 503, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->exception($e, important: true);
            return new Response(400, body: $e->getMessage());
        }
    }

    private static function type(string $path): ?Frame\Type
    {
        foreach (self::PATHS as $suffix => $type) {
            if (\str_ends_with($path, $suffix)) {
                return $type;
            }
        }

        return null;
    }

    private function processStore(ServerRequestInterface $request, Frame\Type $type): ResponseInterface
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > $this->profileBuilder->maxSize) {
            return $this->reject($type, 413, 'The body is too large.');
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
        /** @var mixed $period */
        $period = $query['period'] ?? null;
        if ($period !== null) {
            \is_string($period) && \is_numeric($period) && (float) $period > 0.0 && \is_finite((float) $period)
                or throw new \InvalidArgumentException('The `period` parameter is not a positive number of seconds.');
            $period = (float) $period;
        }
        // phpspy samples on the wall clock
        /** @var mixed $clock */
        $clock = $type === Frame\Type::Collapsed ? $query['clock'] ?? 'wall' : 'wall';
        $clock === 'wall' || $clock === 'cpu'
            or throw new \InvalidArgumentException('The `clock` parameter is neither `wall` nor `cpu`.');

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        // Built before accepting, to reject what is not a profile; one at a time, letting the other fibers run
        $profile = $this->lock->run(function () use ($request, $type, $time, $metadata, $tags, $name, $period, $clock): Profile {
            $content = $this->profileBuilder->decode((string) $request->getBody());

            return $type === Frame\Type::Phpspy
                ? $this->profileBuilder->phpspy($content, $time, $metadata, $tags, $name, $period, new TimeSlice())
                : $this->profileBuilder->collapsed(
                    $content,
                    $time,
                    $metadata,
                    $tags,
                    $name,
                    $period,
                    cpuClock: $clock === 'cpu',
                    timeSlice: new TimeSlice(),
                );
        });

        /** @var mixed $compacted */
        $compacted = $profile->metadata['compacted'] ?? null;
        \is_array($compacted) and $this->logger->warning(
            '%s: the profile is compacted to %d call tree nodes: %s.',
            $type->value,
            (int) ($compacted['limit'] ?? 0),
            Report::summarize($compacted),
        );

        \Fiber::suspend(
            new Frame(
                payload: Frame\Payload::new(type: $type, callsProvider: static fn(): Profile => $profile),
                time: $time,
            ),
        );

        return new Response(200);
    }

    private function reject(Frame\Type $type, int $status, string $reason): ResponseInterface
    {
        $this->logger->warning('%s: profile rejected with %d: %s', $type->value, $status, \lcfirst($reason));

        return new Response($status, body: $reason);
    }
}
