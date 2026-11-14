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
use Buggregator\Trap\Support\Spx\Archive;
use Buggregator\Trap\Support\Spx\Metadata;
use Buggregator\Trap\Support\TimeSlice;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Receives SPX full reports: `POST /api/profiler/spx` with the two files SPX writes to its data directory,
 * the `<key>.json` metadata and the gzipped `<key>.txt.gz` trace, as:
 * - `multipart/form-data` with the `metadata` part, a file or a field, and the `trace` file part;
 * - `application/x-tar`: an archive of the metadata, then the trace, as {@see Archive} packs them.
 *
 * ```
 * curl -F metadata=@/tmp/spx/spx-full-….json -F trace=@/tmp/spx/spx-full-….txt.gz http://127.0.0.1:8000/api/profiler/spx
 * ```
 *
 * Optional parameters, in the query or as form fields, which win: `sampling_period`, the microseconds between
 * the samples when SPX sampled the stack (`SPX_SAMPLING_PERIOD`), which the report doesn't tell; `app_name`,
 * `hostname`, `name` and `tags[name]=value`.
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
     * Reads from the current position to the end, or the length.
     *
     * @param positive-int $limit Bytes the stream may have: its size may be unknown.
     * @param int<0, max>|null $length Bytes to read at most; the rest of the stream when null.
     *
     * @return \Generator<int, string>
     *
     * @throws \LengthException When the stream is larger than the limit.
     */
    private static function chunks(StreamInterface $stream, int $limit, ?int $length = null): \Generator
    {
        $read = 0;
        while (($length === null || $read < $length) && !$stream->eof()) {
            $chunk = $stream->read($length === null ? 65536 : \min(65536, $length - $read));
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
     * `StreamInterface::read()` takes no 0, and may return less than asked.
     *
     * @param int<0, max> $length
     *
     * @return string The bytes from the current position, up to the length.
     */
    private static function read(StreamInterface $stream, int $length): string
    {
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

    /**
     * @return array{string, \Generator<int, string>} The metadata JSON and the trace chunks.
     *
     * @throws \LengthException When the trace is too large.
     */
    private function form(ServerRequestInterface $request): array
    {
        $trace = self::file($request, 'trace')
            ?? throw new \InvalidArgumentException('No `trace` file part: the `.txt.gz` file of the report.');
        ($trace->getSize() ?? 0) > $this->profileBuilder->maxSize
            and throw new \LengthException("The trace is larger than {$this->profileBuilder->maxSize} bytes.");
        $file = self::file($request, 'metadata');
        if ($file === null) {
            $json = self::param($request, 'metadata')
                ?? throw new \InvalidArgumentException('No `metadata` part: the `.json` file of the report.');
        } else {
            $stream = $file->getStream();
            $stream->rewind();
            // A byte over the limit is enough to reject it
            $json = self::read($stream, Metadata::MAX_SIZE + 1);
        }

        $stream = $trace->getStream();
        $stream->rewind();

        return [$json, self::chunks($stream, $this->profileBuilder->maxSize)];
    }

    /**
     * Reads an {@see Archive}: the metadata, then the trace, streamed.
     *
     * @return array{string, \Generator<int, string>} The metadata JSON and the trace chunks.
     *
     * @throws \LengthException When the trace is too large.
     */
    private function archive(StreamInterface $body): array
    {
        $body->rewind();
        $read = static fn(int $length): string => self::read($body, \max(0, $length));

        [$name, $size] = Archive::next($read) ?? throw new \InvalidArgumentException('The archive is empty.');
        \str_ends_with($name, '.json') && $size <= Metadata::MAX_SIZE
            or throw new \InvalidArgumentException('The archive must start with the `.json` metadata of the report.');
        $json = $read($size);
        $read(Archive::paddingOf($size));

        [$name, $size] = Archive::next($read)
            ?? throw new \InvalidArgumentException('The archive has no `.txt.gz` trace after the metadata.');
        \str_ends_with($name, '.txt.gz')
            or throw new \InvalidArgumentException('The archive has no `.txt.gz` trace after the metadata.');
        $size > $this->profileBuilder->maxSize
            and throw new \LengthException("The trace is larger than {$this->profileBuilder->maxSize} bytes.");

        return [$json, self::chunks($body, $this->profileBuilder->maxSize, $size)];
    }

    private function processStore(ServerRequestInterface $request): ResponseInterface
    {
        // A body of an unknown size is limited as it is read
        $size = $request->getBody()->getSize();
        if ($size !== null && $size > $this->profileBuilder->maxSize + Metadata::MAX_SIZE + self::FORM_OVERHEAD) {
            return $this->reject(413, 'The body is too large.');
        }

        $type = \strtolower($request->getHeaderLine('Content-Type'));
        try {
            [$json, $chunks] = match (true) {
                \str_starts_with($type, 'multipart/form-data') => $this->form($request),
                \str_starts_with($type, 'application/x-tar') => $this->archive($request->getBody()),
                default => throw new \InvalidArgumentException(
                    'Send the report as multipart/form-data with the `metadata` and `trace` parts, '
                    . 'or as application/x-tar.',
                ),
            };
        } catch (\LengthException $e) {
            return $this->reject(413, $e->getMessage());
        }
        $spx = Metadata::fromJson($json);

        $period = self::param($request, 'sampling_period');
        $period === null || \ctype_digit($period) && (int) $period > 0
            or throw new \InvalidArgumentException('The `sampling_period` must be a positive number of microseconds.');

        $metadata = [];
        foreach (['app_name', 'hostname'] as $key) {
            ($value = self::param($request, $key)) === null or $metadata[$key] = $value;
        }
        $tags = self::tags($request);
        ($profileName = self::param($request, 'name')) === null or $tags += ['name' => $profileName];

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        // Built before accepting, to reject what is not a report; one build at a time, the other fibers run meanwhile.
        // Dated by the profiled run, which the report tells, rather than by the upload.
        $profile = $this->lock->run(fn(): Profile => $this->profileBuilder->createProfile(
            $spx,
            $chunks,
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
