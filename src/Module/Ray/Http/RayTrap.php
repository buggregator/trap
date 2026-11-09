<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray\Http;

use Buggregator\Trap\Handler\Http\Handler\Fallback;
use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Frontend\UiChannel;
use Buggregator\Trap\Module\Ray\Frame;
use Buggregator\Trap\Module\Ray\Lock\Locks;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Receives the requests of Ray clients (spatie/ray and compatible).
 *
 * - `GET /_availability_check`: a client sends payloads only when this answers with an HTTP error status.
 * - `POST /` with `{"uuid", "payloads", "meta"}`: payloads, turned into a {@see Frame}.
 * - `GET /locks/{name}`: a paused client polls it every second. `active: true` keeps it paused,
 *   `stop_execution: true` makes it throw.
 *
 * Locks are held only while the web UI runs: its buttons are the only way to release them.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class RayTrap implements Middleware
{
    private const MAX_BODY_SIZE = 10 * 1024 * 1024;

    private bool $warnedNoUi = false;

    public function __construct(
        private readonly Locks $locks,
        private readonly UiChannel $ui,
        private readonly ?Logger $logger = null,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (\str_ends_with($path, '_availability_check')) {
            return new Response(400, [Fallback::NO_DUMP_HEADER => '1']);
        }

        $fromClient = self::isRayClient($request);
        $method = $request->getMethod();

        if ($method === 'GET' && \preg_match('#^/locks/([^/]++)$#', $path, $matches) === 1
            && ($fromClient || $this->locks->has($matches[1]))
        ) {
            return $this->lockState($matches[1]);
        }

        if ($method === 'POST' && ($fromClient || self::mayCarryPayloads($request))) {
            return $this->payloads($request, $fromClient) ?? $next($request);
        }

        return $next($request);
    }

    /**
     * spatie/ray sends the `Ray 1.0` user agent; other clients may mark a request with the header
     * Buggregator uses to detect an event type.
     */
    private static function isRayClient(ServerRequestInterface $request): bool
    {
        return \stripos($request->getHeaderLine('User-Agent'), 'ray') === 0
            || \strtolower($request->getHeaderLine('X-Buggregator-Event')) === 'ray';
    }

    private static function mayCarryPayloads(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getPath() === '/'
            && \str_contains($request->getHeaderLine('Content-Type'), 'json');
    }

    /**
     * An unknown lock answers `active: false`: a client paused before a restart of trap continues
     * instead of waiting forever.
     */
    private function lockState(string $name): ResponseInterface
    {
        $lock = $this->locks->poll($name);

        return new Response(
            200,
            ['Content-Type' => 'application/json', Fallback::NO_DUMP_HEADER => '1'],
            Json::encode([
                'active' => $lock?->isPending() ?? false,
                'stop_execution' => $lock?->stop === true,
            ]),
        );
    }

    /**
     * @param bool $fromClient Whether the request is known to come from a Ray client.
     *
     * @return ResponseInterface|null Null when the request is not a Ray one and goes on through the pipeline.
     */
    private function payloads(ServerRequestInterface $request, bool $fromClient): ?ResponseInterface
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > self::MAX_BODY_SIZE) {
            return $fromClient ? new Response(413) : null;
        }

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        try {
            $frame = Frame::fromString((string) $request->getBody(), $time);
        } catch (\JsonException|\InvalidArgumentException) {
            return $fromClient ? new Response(400) : null;
        }

        $this->holdLocks($frame);

        \Fiber::suspend($frame);

        return new Response(200);
    }

    /**
     * Called before the frame is handed over: the client polls the lock right after the response.
     * Without the UI no lock is created, and the poll of an unknown lock lets the client continue.
     */
    private function holdLocks(Frame $frame): void
    {
        $names = $frame->lockNames();
        if ($names === []) {
            return;
        }

        if (!$this->ui->isEnabled()) {
            $this->warnedNoUi or $this->logger?->status(
                'Ray',
                'pause() does not wait: continuing or stopping the execution needs the web UI (--ui).',
            );
            $this->warnedNoUi = true;
            return;
        }

        foreach ($names as $name) {
            $this->locks->create($name);
        }
    }
}
