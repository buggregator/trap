<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Http;

use Buggregator\Trap\Module\Frontend\Http\StaticFiles;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(StaticFiles::class)]
#[Test]
final class StaticFilesTest
{
    use FiberTrait;

    private const HTML = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    public function testServesAssetWithAtSignInName(): void
    {
        $response = self::handle(new ServerRequest('GET', '/assets/' . self::asset('@vue-*.js')));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Content-Type'), 'application/javascript');
    }

    public function testServesFontAsWoff2(): void
    {
        $response = self::handle(new ServerRequest('GET', '/assets/' . self::asset('*.woff2')));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Content-Type'), 'font/woff2');
    }

    public function testEarlyHintsPreloadStylesInCorsModeOfTheirTags(): void
    {
        $fiber = new \Fiber(static fn(): ResponseInterface => self::handle(new ServerRequest('GET', '/')));
        $hints = $fiber->start();

        Assert::instanceOf($hints, ResponseInterface::class);
        Assert::same($hints->getStatusCode(), 103);
        Assert::string($hints->getHeaderLine('Link'))->contains('; rel=preload; as=style; crossorigin');

        $fiber->resume();
        Assert::same($fiber->getReturn()->getStatusCode(), 200);
    }

    #[DataSet(['/'])]
    #[DataSet(['/smtp'])]
    #[DataSet(['/smtp/0198c2d4-7a54-7b81-9b8e-3cf7c6c3a6b1'])]
    #[DataSet(['/http-dump/0198c2d4-7a54-7b81-9b8e-3cf7c6c3a6b1'])]
    #[DataSet(['/sentry/traces/4bf92f3577b34da6a3ce929d0e0e4736'])]
    #[DataSet(['/profiler/compare'])]
    #[DataSet(['/settings'])]
    #[DataSet(['/favorites'])]
    public function testUiPageOpenedInBrowserGetsIndex(string $path): void
    {
        $response = $this->runInFiber(
            static fn(): ResponseInterface => self::handle(new ServerRequest('GET', $path, ['Accept' => self::HTML])),
        );

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Content-Type'), 'text/html; charset=utf-8');
        Assert::same($response->getHeaderLine('Cache-Control'), 'no-cache');
        Assert::string((string) $response->getBody())->contains('<div id="app">');
    }

    /**
     * The UI shares its port with HTTP dumps: these go further.
     */
    #[DataSet(['GET', '/smtp/0198c2d4-7a54-7b81-9b8e-3cf7c6c3a6b1', '*/*'], 'not a browser')]
    #[DataSet(['POST', '/smtp', self::HTML], 'not a GET')]
    #[DataSet(['POST', '/', '*/*'], 'POST to the root')]
    #[DataSet(['GET', '/checkout/cart', self::HTML], 'not a UI page')]
    #[DataSet(['GET', '/smtp/a/b', self::HTML], 'too deep')]
    #[DataSet(['GET', '/api/events', self::HTML], 'API')]
    #[DataSet(['GET', '/connection/websocket', self::HTML], 'WebSocket')]
    #[DataSet(['GET', '/wp-login.php', self::HTML], 'not a UI file')]
    #[DataSet(['GET', '/assets/missing.js', '*/*'], 'missing asset')]
    public function testOtherRequestGoesFurther(string $method, string $path, string $accept): void
    {
        $response = self::handle(new ServerRequest($method, $path, ['Accept' => $accept]));

        Assert::same($response->getStatusCode(), 418);
    }

    private static function handle(ServerRequest $request): ResponseInterface
    {
        return (new StaticFiles())->handle($request, static fn(): Response => new Response(418));
    }

    /**
     * @param non-empty-string $pattern Glob in the assets of the UI build, which names them by hashes.
     */
    private static function asset(string $pattern): string
    {
        $files = \glob(Info::TRAP_ROOT . '/resources/frontend/assets/' . $pattern);
        Assert::notBlank($files);

        return \basename($files[0]);
    }
}
