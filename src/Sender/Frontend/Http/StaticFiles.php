<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Http;

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

        if (\preg_match('#^/((?:[a-zA-Z0-9\\-_]+/)*[a-zA-Z0-9.\\-\\[\\]() _]+?\\.([a-zA-Z0-4]++))$#', $path, $matches)) {
            $file = \sprintf('%s/resources/frontend/%s', Info::TRAP_ROOT, $matches[1]);

            /** @var array<non-empty-string, string> $cacheContent */
            static $cacheContent = [];
            /** @var array<non-empty-string, non-empty-string> $cacheHash */
            static $cacheHash = [];
            /** @var array<non-empty-string, int<0, max>> $cacheSize */
            static $cacheSize = [];

            if (!\array_key_exists($file, $cacheContent) && !\is_file($file)) {
                return new Response(404);
            }

            $headers = [];

            $type = match($matches[2]) {
                'css' => 'text/css',
                'html' => 'text/html',
                'txt' => 'text/plain',
                'js' => 'application/javascript',
                'ico' => 'image/x-icon',
                'png' => 'image/png',
                'json' => 'application/json',
                'svg' => 'image/svg+xml',
                'xml' => 'application/xml',
                'webmanifest' => 'application/manifest+json',
                default => 'octet/stream',
            };

            if ($path === '/index.html') {
                if (empty($this->earlyResponse)) {
                    $cacheContent[$file] ??= \file_get_contents($file);
                    // Find all CSS files
                    \preg_match_all(
                        '#\\bhref="([^"]+?\\.css)"#i',
                        $cacheContent[$file],
                        $matches,
                    );
                    $this->earlyResponse = \array_unique($matches[1]);
                }

                $headers = [
                    'Link' => \array_map(
                        static fn(string $css): string => \sprintf('<%s>; rel=preload; as=style', $css),
                        $this->earlyResponse,
                    ),
                ];
                empty($this->earlyResponse) or \Fiber::suspend(new Response(103, $headers));
                // (new \Buggregator\Trap\Support\Timer(2))->wait(); // to test early hints
            }

            $cacheContent[$file] ??= \file_get_contents($file);
            return new Response(
                200,
                [
                    'Content-Type' => [$type],
                    'Content-Length' => [$cacheSize[$file] ??= \filesize($file)],
                    'Date' => [\gmdate('D, d M Y H:i:s T')],
                    'Cache-Control' => ['max-age=604801'],
                    'Accept-Ranges' => ['none'],
                    'ETag' => [$cacheHash[$file] ??= \sha1($cacheContent[$file])],
                ] + $headers,
                $cacheContent[$file],
            );
        }

        return $next($request);
    }
}
