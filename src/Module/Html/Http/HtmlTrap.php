<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html\Http;

use Buggregator\Trap\Module\Html\Frame;
use Buggregator\Trap\Module\Html\Page;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Receives the sender's own HTML pages, as buggregator/server does: a POST to `/html` or `/html/{project}`,
 * or a POST to any path marked `html` by `X-Buggregator-Event` or the user of Basic authorization,
 * as `http://html:{project}@127.0.0.1:8000` sends. A request marked for another module is left to it, and so is
 * any other method: `GET /html/{uuid}` is a page of the web UI.
 *
 * The project is the `{project}` of the path, else the password of the Basic user `html`, else the
 * `X-Buggregator-Project` header.
 *
 * A page is answered with 204; a rejected one with the reason in plain text.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class HtmlTrap implements Middleware
{
    public const MAX_BODY_SIZE = 1024 * 1024;

    public function __construct(
        private readonly ?Logger $logger = null,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $marked = self::markedType($request);
        if ($request->getMethod() !== 'POST'
            || $marked !== '' && $marked !== Frame::TYPE
            || $marked === '' && $path !== '/html' && !\str_starts_with($path, '/html/')
        ) {
            return $next($request);
        }

        $size = $request->getBody()->getSize();
        if ($size === null || $size > self::MAX_BODY_SIZE) {
            return $this->reject(413, 'The body is larger than 1 MiB.');
        }

        try {
            [$title, $html] = Page::parse($request->getHeaderLine('Content-Type'), (string) $request->getBody());
        } catch (\InvalidArgumentException $e) {
            return $this->reject(400, $e->getMessage());
        }

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        \Fiber::suspend(new Frame(
            $title,
            $html,
            self::project($request),
            $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable(),
        ));

        return new Response(204);
    }

    /**
     * The event type the client marked the request with: `X-Buggregator-Event`, else the user of Basic authorization.
     *
     * @return string Empty for an unmarked request.
     */
    private static function markedType(ServerRequestInterface $request): string
    {
        $event = \strtolower(\trim($request->getHeaderLine('X-Buggregator-Event')));

        return $event === '' ? self::basicAuth($request)[0] : $event;
    }

    /**
     * @return non-empty-string|null
     */
    private static function project(ServerRequestInterface $request): ?string
    {
        $path = $request->getUri()->getPath();
        $project = \str_starts_with($path, '/html/') ? \trim(\rawurldecode(\substr($path, 6)), '/ ') : '';
        if ($project === '') {
            [$user, $password] = self::basicAuth($request);
            $project = $user === Frame::TYPE ? $password : '';
        }
        $project === '' and $project = \trim($request->getHeaderLine('X-Buggregator-Project'));

        return $project === '' ? null : $project;
    }

    /**
     * A URL like `http://html:shop@host/` comes as Basic authorization.
     *
     * @return array{string, string} The user and the password; empty strings without Basic authorization.
     */
    private static function basicAuth(ServerRequestInterface $request): array
    {
        $header = $request->getHeaderLine('Authorization');
        $credentials = \strncasecmp($header, 'Basic ', 6) === 0
            ? \base64_decode(\trim(\substr($header, 6)), true)
            : false;
        if ($credentials === false) {
            return ['', ''];
        }

        $parts = \explode(':', $credentials, 2);

        return [\strtolower($parts[0]), $parts[1] ?? ''];
    }

    private function reject(int $status, string $reason): ResponseInterface
    {
        $this->logger?->warning('HTML: page rejected with %d: %s', $status, \lcfirst($reason));

        return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8'], "$reason\n");
    }
}
