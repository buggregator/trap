<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Http;

use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Info;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Files of the bundled UI build, and its `index.html` for the pages of the UI.
 *
 * The UI routes in the browser history: a reload or a link opens a page like `/smtp/{uuid}`,
 * which only `index.html` can serve. Such a path is taken for the UI when a browser asks for HTML
 * of a page the UI has: the UI shares its port with HTTP dumps, and any other request is left to them.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class StaticFiles implements Middleware
{
    /**
     * Event types of the UI build: its `/{type}` and `/{type}/{id}` pages.
     */
    private const UI_EVENT_TYPES = 'var-dump|smtp|sentry|profiler|monolog|inspector|http-dump|ray|sms|csp'
        . '|sentry-trace|sentry-log|xdebug-trace';

    /**
     * Pages of the UI router besides the event ones.
     */
    private const UI_PAGES = 'settings|login|favorites|metrics|logs|xdebug|mock-server|404|profiler/compare|sentry(?:/.*)?';

    private array $earlyResponse = [];

    /**
     * Whether the request opens a page of the UI in a browser.
     */
    public static function isUiPage(ServerRequestInterface $request): bool
    {
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        $path = $request->getUri()->getPath();
        if ($path === '/') {
            return true;
        }

        return \str_contains($request->getHeaderLine('Accept'), 'text/html') && \preg_match(
            \sprintf('#^/(?:%s|(?:%s)(?:/[^/]++)?)/?$#', self::UI_PAGES, self::UI_EVENT_TYPES),
            $path,
        ) === 1;
    }

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $path = self::isUiPage($request) ? '/index.html' : $request->getUri()->getPath();

        if (\preg_match('#^/((?:[a-zA-Z0-9@\\-_]+/)*[a-zA-Z0-9@.\\-\\[\\]() _]+?\\.([a-zA-Z0-9]++))$#', $path, $matches)) {
            $file = \sprintf('%s/resources/frontend/%s', Info::TRAP_ROOT, $matches[1]);

            /** @var array<non-empty-string, string> $cacheContent */
            static $cacheContent = [];
            /** @var array<non-empty-string, non-empty-string> $cacheHash */
            static $cacheHash = [];
            /** @var array<non-empty-string, int<0, max>> $cacheSize */
            static $cacheSize = [];

            // Not a file of the UI: an HTTP dump, maybe
            if (!\array_key_exists($file, $cacheContent) && !\is_file($file)) {
                return $next($request);
            }

            $headers = [];

            $type = match (\strtolower($matches[2])) {
                'css' => 'text/css',
                'html' => 'text/html; charset=utf-8',
                'txt' => 'text/plain',
                'js' => 'application/javascript',
                'ico' => 'image/x-icon',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'woff2' => 'font/woff2',
                'woff' => 'font/woff',
                'json' => $path === '/manifest.json' ? 'application/manifest+json' : 'application/json',
                'xml' => 'application/xml',
                'webmanifest' => 'application/manifest+json',
                'map' => 'application/json',
                default => 'application/octet-stream',
            };

            // The build names its assets by their hashes, and index.html refers to the ones of the build
            $cache = $path === '/index.html' ? 'no-cache' : 'max-age=604801';

            if ($path === '/index.html') {
                if (empty($this->earlyResponse)) {
                    $cacheContent[$file] ??= self::readFile($file);
                    // A preload is used only with the CORS mode of the tag: `crossorigin` of a Vite build
                    \preg_match_all(
                        '#<link\\b[^>]*?\\bhref="([^"]+?\\.css)"[^>]*>#i',
                        $cacheContent[$file],
                        $matches,
                        \PREG_SET_ORDER,
                    );
                    $this->earlyResponse = \array_values(\array_unique(\array_map(
                        static fn(array $tag): string => \sprintf(
                            '<%s>; rel=preload; as=style%s',
                            $tag[1],
                            \preg_match('#\\scrossorigin\\b#i', $tag[0]) === 1 ? '; crossorigin' : '',
                        ),
                        $matches,
                    )));
                }

                $headers = ['Link' => $this->earlyResponse];
                empty($this->earlyResponse) or \Fiber::suspend(new Response(103, $headers));
            }

            $cacheContent[$file] ??= self::readFile($file);
            return new Response(
                200,
                [
                    'Content-Type' => [$type],
                    'Content-Length' => [$cacheSize[$file] ??= \filesize($file)],
                    'Date' => [\gmdate('D, d M Y H:i:s T')],
                    'Cache-Control' => [$cache],
                    'Accept-Ranges' => ['none'],
                    'ETag' => [$cacheHash[$file] ??= \hash('xxh128', $cacheContent[$file])],
                ] + $headers,
                $request->getMethod() === 'HEAD' ? '' : $cacheContent[$file],
            );
        }

        return $next($request);
    }

    private static function readFile(string $file): string
    {
        $content = \file_get_contents($file);
        $content === false and throw new \RuntimeException(\sprintf('Unable to read the file `%s`.', $file));

        return $content;
    }
}
