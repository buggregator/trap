<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Csp\Http;

use Buggregator\Trap\Module\Csp\Frame;
use Buggregator\Trap\Module\Csp\Report;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Receives browser security reports, as buggregator/server does: a POST to any path with the
 * `application/csp-report` or `application/reports+json` content type, or a POST to `/csp` or `/csp/{project}`.
 *
 * A request marked with `X-Buggregator-Event` or the user of Basic authorization is taken when marked `csp`,
 * whatever its path and content type, and left to the other module otherwise. The project is the `{project}`
 * of the path, else the password of the Basic user `csp`, else the `X-Buggregator-Project` header.
 *
 * A report is answered with 204: browsers read nothing from the answer. An `OPTIONS` preflight to `/csp`
 * and `/csp/{project}` is answered for any origin.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class CspTrap implements Middleware
{
    /** Browsers send a few kilobytes. */
    public const MAX_BODY_SIZE = 1024 * 1024;

    public function __construct(
        private readonly ?Logger $logger = null,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $ownPath = $path === '/csp' || \str_starts_with($path, '/csp/');
        if ($ownPath && $request->getMethod() === 'OPTIONS') {
            return self::preflight();
        }

        $format = Report::formatOf($request->getHeaderLine('Content-Type'));
        $marked = self::markedType($request);
        if ($request->getMethod() !== 'POST'
            || $marked !== '' && $marked !== Frame::TYPE
            || $marked === '' && $format === null && !$ownPath
        ) {
            return $next($request);
        }

        $size = $request->getBody()->getSize();
        if ($size === null || $size > self::MAX_BODY_SIZE) {
            return $this->reject(413, 'the body is larger than 1 MiB.');
        }

        try {
            [$format, $reports] = Report::parse($format, (string) $request->getBody());
        } catch (\InvalidArgumentException $e) {
            return $this->reject(400, \lcfirst($e->getMessage()));
        }

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        \Fiber::suspend(new Frame(
            $format,
            $reports,
            self::project($request),
            $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable(),
        ));

        return new Response(204, ['Access-Control-Allow-Origin' => '*']);
    }

    /**
     * Chrome delivers Reporting API reports to another origin only after a CORS preflight succeeds,
     * and drops them otherwise.
     */
    private static function preflight(): ResponseInterface
    {
        return new Response(204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '86400',
        ]);
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
        $project = \str_starts_with($path, '/csp/') ? \trim(\rawurldecode(\substr($path, 5)), '/ ') : '';
        if ($project === '') {
            [$user, $password] = self::basicAuth($request);
            $project = $user === Frame::TYPE ? $password : '';
        }
        $project === '' and $project = \trim($request->getHeaderLine('X-Buggregator-Project'));

        return $project === '' ? null : $project;
    }

    /**
     * A URL like `http://csp:shop@host/` comes as Basic authorization.
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
        $this->logger?->warning('CSP: report rejected with %d: %s', $status, $reason);

        return new Response($status);
    }
}
