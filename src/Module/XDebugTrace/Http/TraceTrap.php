<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Http;

use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Module\XDebugTrace\Trace\TraceError;
use Buggregator\Trap\Module\XDebugTrace\TraceStorage;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Support\TimeSlice;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Receives Xdebug function traces (`xdebug.trace_format=1`), as Buggregator Server does: a POST to any path
 * with the header `X-Buggregator-Event: xdebug-trace`, or with `xdebug-trace` as the user of Basic authorization,
 * and the `.xt` or `.xt.gz` file as the body.
 *
 * Optional headers: `X-Buggregator-Trace-Name`, `X-Buggregator-Trace-Url` and `X-Buggregator-Project`.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class TraceTrap implements Middleware
{
    private const MAX_HEADER = 2048;

    public function __construct(
        private readonly TraceStorage $storage,
        private readonly Logger $logger,
    ) {}

    public static function matches(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'POST' && (
            \strtolower($request->getHeaderLine('X-Buggregator-Event')) === Frame::TYPE
            || self::basicUser($request) === Frame::TYPE
        );
    }

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if (!self::matches($request)) {
            return $next($request);
        }

        $body = $request->getBody();
        $body->isSeekable() and $body->rewind();
        $source = $body->detach();
        if ($source === null) {
            return new Response(400, body: 'The trace has no body.');
        }

        try {
            $trace = $this->storage->store($source, new TimeSlice());
        } catch (TraceError $e) {
            $this->logger->warning('Xdebug trace rejected: %s', $e->getMessage());
            return new Response(match ($e->reason) {
                TraceError::TOO_LARGE => 413,
                TraceError::NO_ZLIB => 501,
                default => 400,
            }, body: $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->exception($e, important: true);
            return new Response(500, body: $e->getMessage());
        } finally {
            \fclose($source);
        }

        $trace->summary['truncated'] and $this->logger->warning(
            'Xdebug trace %s is cut: it has no end or is larger than the limit.',
            $trace->uuid,
        );

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $project = self::header($request, 'X-Buggregator-Project');
        \Fiber::suspend(Frame::fromStored(
            $trace,
            name: self::header($request, 'X-Buggregator-Trace-Name'),
            url: self::header($request, 'X-Buggregator-Trace-Url'),
            project: $project === '' ? null : $project,
            time: $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable(),
        ));

        return new Response(200);
    }

    /**
     * Clients send the user of a URL as `http://xdebug-trace@host/` in Basic authorization.
     */
    private static function basicUser(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (\strncasecmp($header, 'Basic ', 6) !== 0) {
            return null;
        }
        $credentials = \base64_decode(\trim(\substr($header, 6)), true);

        return $credentials === false ? null : \explode(':', $credentials, 2)[0];
    }

    private static function header(ServerRequestInterface $request, string $name): string
    {
        return \substr(\trim($request->getHeaderLine($name)), 0, self::MAX_HEADER);
    }
}
