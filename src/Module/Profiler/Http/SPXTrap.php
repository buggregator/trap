<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\BuildQueueFull;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\SPX\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Support\Spx\Metadata;
use Buggregator\Trap\Support\TimeSlice;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Receives SPX full reports: `POST /api/profiler/spx` as `multipart/form-data` with two parts,
 * as SPX writes them to its data directory:
 * - `metadata`: the `<key>.json` file, or a field with the JSON;
 * - `trace`: the gzipped `<key>.txt.gz` file.
 *
 * ```
 * curl -F metadata=@/tmp/spx/spx-full-….json -F trace=@/tmp/spx/spx-full-….txt.gz http://127.0.0.1:8000/api/profiler/spx
 * ```
 *
 * Optional parameters, in the query or as form fields, which win: `sampling_period`, the microseconds between the samples
 * when SPX sampled the stack (`SPX_SAMPLING_PERIOD`), which the report doesn't tell; `app_name`, `hostname`
 * and `tags[name]=value`.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class SPXTrap implements Middleware
{
    private const PATH = '/api/profiler/spx';

    /** Bytes of the form besides the parts: boundaries, headers and fields. */
    private const FORM_OVERHEAD = 64 * 1024;

    /**
     * @param bool|null $zlib Whether ext-zlib is there, which reading a trace takes; by default, whether it is loaded.
     */
    public function __construct(
        private readonly ProfileBuilder $profileBuilder,
        private readonly Logger $logger,
        private readonly BuildLock $lock,
        private readonly ?bool $zlib = null,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if ($request->getMethod() !== 'POST' || !\str_ends_with($request->getUri()->getPath(), self::PATH)) {
            return $next($request);
        }

        if (!($this->zlib ?? \function_exists('inflate_init'))) {
            return $this->reject(501, 'Trap can not read SPX traces: its PHP has no ext-zlib.');
        }

        try {
            return $this->processStore($request);
        } catch (\InvalidArgumentException $e) {
            return $this->reject(400, $e->getMessage());
        } catch (\LengthException $e) {
            return $this->reject(413, $e->getMessage());
        } catch (BuildQueueFull $e) {
            return $this->reject(503, $e->getMessage());
        } catch (\Throwable $e) {
            // Only the errors of the report are told to the client: others may carry paths and internals
            $this->logger->exception($e, important: true);
            return new Response(500, body: 'Unable to store the report, see the Trap log.');
        }
    }

    /**
     * @param positive-int $limit Bytes the stream may have: its size may be unknown.
     *
     * @return \Generator<int, string>
     *
     * @throws \LengthException When the stream is larger.
     */
    private static function chunks(StreamInterface $stream, int $limit): \Generator
    {
        $stream->rewind();
        $read = 0;
        while (!$stream->eof()) {
            $chunk = $stream->read(65536);
            // A stream that tells no end would loop forever
            if ($chunk === '') {
                break;
            }
            $read += \strlen($chunk);
            $read > $limit and throw new \LengthException("The trace is larger than $limit bytes.");
            yield $chunk;
        }
    }

    /**
     * @param positive-int $length
     *
     * @return string The start of the stream, up to the length: its size may be unknown.
     */
    private static function head(StreamInterface $stream, int $length): string
    {
        $stream->rewind();
        $data = '';
        while (\strlen($data) < $length && !$stream->eof()) {
            $chunk = $stream->read($length - \strlen($data));
            if ($chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @return non-empty-string|null
     */
    private static function param(ServerRequestInterface $request, string $name): ?string
    {
        /** @var mixed $body */
        $body = $request->getParsedBody();
        /** @var mixed $value */
        $value = (\is_array($body) ? $body[$name] ?? null : null) ?? $request->getQueryParams()[$name] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Tags of the query, then of the form, which win. The server keeps the names of the form fields as they are,
     * `tags[name]`, where a query string is parsed into a `tags` array.
     *
     * @return array<non-empty-string, non-empty-string>
     */
    private static function tags(ServerRequestInterface $request): array
    {
        /** @var mixed $body */
        $body = $request->getParsedBody();
        $tags = [];
        foreach ([$request->getQueryParams(), \is_array($body) ? $body : []] as $params) {
            /** @var mixed $value */
            foreach ($params as $key => $value) {
                /** @var array<array-key, mixed> $found */
                $found = match (true) {
                    $key === 'tags' && \is_array($value) => $value,
                    \is_string($key) && \preg_match('/^tags\[([^\]]+)\]$/', $key, $m) === 1 => [$m[1] => $value],
                    default => [],
                };
                /** @var mixed $tag */
                foreach ($found as $name => $tag) {
                    \is_string($name) && $name !== '' && \is_string($tag) && $tag !== '' and $tags[$name] = $tag;
                }
            }
        }

        return $tags;
    }

    private static function file(ServerRequestInterface $request, string $name): ?UploadedFileInterface
    {
        /** @var mixed $files */
        $files = $request->getUploadedFiles()[$name] ?? null;
        /** @var mixed $file */
        $file = \is_array($files) ? \reset($files) : $files;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    private function processStore(ServerRequestInterface $request): ResponseInterface
    {
        // A body of an unknown size is limited as it is read
        $size = $request->getBody()->getSize();
        if ($size !== null && $size > $this->profileBuilder->maxSize + Metadata::MAX_SIZE + self::FORM_OVERHEAD) {
            return $this->reject(413, 'The body is too large.');
        }
        \str_starts_with(\strtolower($request->getHeaderLine('Content-Type')), 'multipart/form-data')
            or throw new \InvalidArgumentException(
                'Send the report as multipart/form-data with the `metadata` and `trace` parts.',
            );

        $trace = self::file($request, 'trace')
            ?? throw new \InvalidArgumentException('No `trace` file part: the `.txt.gz` file of the report.');
        if (($trace->getSize() ?? 0) > $this->profileBuilder->maxSize) {
            return $this->reject(413, "The trace is larger than {$this->profileBuilder->maxSize} bytes.");
        }
        $file = self::file($request, 'metadata');
        $json = $file === null ? self::param($request, 'metadata') : self::head($file->getStream(), Metadata::MAX_SIZE + 1);
        $json ?? throw new \InvalidArgumentException('No `metadata` part: the `.json` file of the report.');
        $spx = Metadata::fromJson($json);

        $period = self::param($request, 'sampling_period');
        $period === null || \ctype_digit($period) && (int) $period > 0
            or throw new \InvalidArgumentException('The `sampling_period` must be a positive number of microseconds.');

        $metadata = [];
        foreach (['app_name', 'hostname'] as $key) {
            ($value = self::param($request, $key)) === null or $metadata[$key] = $value;
        }
        $tags = self::tags($request);

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        // Built before accepting, to reject what is not a report; one build at a time, the other fibers run meanwhile.
        // Dated by the profiled run, which the report tells, rather than by the upload.
        $profile = $this->lock->run(fn(): Profile => $this->profileBuilder->createProfile(
            $spx,
            self::chunks($trace->getStream(), $this->profileBuilder->maxSize),
            null,
            $metadata,
            $tags,
            $period === null ? null : \max(1, (int) $period),
            new TimeSlice(),
        ));

        /** @var mixed $compacted */
        $compacted = $profile->metadata['compacted'] ?? null;
        \is_array($compacted) and $this->logger->warning(
            'SPX: the profile is compacted to %d call tree nodes: %s.',
            (int) ($compacted['limit'] ?? 0),
            Report::summarize($compacted),
        );

        \Fiber::suspend(
            new Frame(
                payload: Frame\Payload::new(
                    type: Frame\Type::SPX,
                    callsProvider: static fn(): Profile => $profile,
                ),
                time: $time,
            ),
        );

        return new Response(200);
    }

    private function reject(int $status, string $reason): ResponseInterface
    {
        $this->logger->warning('SPX: profile rejected with %d: %s', $status, \lcfirst($reason));

        return new Response($status, body: $reason);
    }
}
