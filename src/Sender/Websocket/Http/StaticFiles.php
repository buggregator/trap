<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket\Http;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Info;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class StaticFiles implements Middleware
{
    private array $earlyResponse = [];

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/') {
            $path = '/index.html';
        }

        if (\preg_match('#^/((?:[a-zA-Z0-9\\-_]+/)?[a-zA-Z0-9.\\-\\[\\]() _]+?\\.([a-zA-Z0-4]++))$#', $path, $matches)) {
            $file = \sprintf("%s/resources/frontend/%s", Info::TRAP_ROOT, $matches[1]);

            if (!\is_file($file)) {
                return new Response(404);
            }
            $content = null;

            $type = match($matches[2]) {
                'css' => 'text/css',
                'html' => 'text/html',
                'txt' => 'text/plain',
                'js' => 'application/javascript',
                'ico' => 'image/x-icon',
                'png' => 'image/png',
                'svg' => 'image/svg+xml',
                'xml' => 'application/xml',
                'webmanifest' => 'application/manifest+json',
                default => 'octet/stream',
            };

            if ($path === '/index.html') {
                if (empty($this->earlyResponse)) {
                    $content = \file_get_contents($file);
                    // Find all CSS files
                    \preg_match_all(
                        '#\\bhref="([^"]+?\\.css)"#i',
                        $content,
                        $matches,
                    );
                    $this->earlyResponse = $matches[1];
                }

                empty($this->earlyResponse) or \Fiber::suspend(
                    new Response(
                        103,
                        [
                            'Link' => \array_map(
                                static fn (string $css): string => \sprintf('<%s>; rel=preload; as=style', $css),
                                $this->earlyResponse,
                            ),
                        ],
                    ),
                );
                // (new \Buggregator\Trap\Support\Timer(5))->wait(); // to test early hints
            }


            return new Response(
                200,
                [
                    'Content-Type' => $type,
                    'Content-Length' => \filesize($file),
                ],
                $content ?? \file_get_contents($file),
            );
        }

        return $next($request);
    }
}
