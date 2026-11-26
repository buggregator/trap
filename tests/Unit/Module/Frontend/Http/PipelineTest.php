<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Http;

use Buggregator\Trap\Module\Frontend\Http\Pipeline;
use Internal\Container\ObjectContainer;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Pipeline::class)]
#[Test]
final class PipelineTest
{
    public function testUiAnswersItsRequest(): void
    {
        $response = self::pipeline()->handle(new ServerRequest('GET', '/api/version'), self::notFound(...));

        Assert::same($response->getStatusCode(), 200);
        Assert::true($response->hasHeader(Pipeline::FRONTEND_HEADER));
    }

    public function testRequestUnknownToUiGoesFurther(): void
    {
        $response = self::pipeline()->handle(new ServerRequest('POST', '/some/app/endpoint'), self::notFound(...));

        Assert::same($response->getStatusCode(), 404);
        Assert::false($response->hasHeader(Pipeline::FRONTEND_HEADER));
    }

    /**
     * The UI calls endpoints of the Buggregator server Trap has not, as `/api/update`.
     */
    public function testUnknownApiCallOfUiPageIsNotDumped(): void
    {
        $request = new ServerRequest('GET', '/api/update', ['Sec-Fetch-Site' => 'same-origin']);

        $response = self::pipeline()->handle($request, static fn(): Response => new Response(418));

        Assert::same($response->getStatusCode(), 404);
        Assert::true($response->hasHeader(Pipeline::FRONTEND_HEADER));
    }

    public function testUnknownApiCallOfOtherPageGoesFurther(): void
    {
        $request = new ServerRequest('GET', '/api/update', ['Sec-Fetch-Site' => 'cross-site']);

        $response = self::pipeline()->handle($request, static fn(): Response => new Response(418));

        Assert::same($response->getStatusCode(), 418);
    }

    public function testControllerStatusDoesNotGoFurther(): void
    {
        $request = new ServerRequest('GET', '/api/xdebug-traces/0190402f-7eb2-7287-82a8-897a0091f58e/summary');

        $response = self::pipeline()->handle($request, self::notFound(...));

        Assert::same($response->getStatusCode(), 404);
        Assert::true($response->hasHeader(Pipeline::FRONTEND_HEADER));
        Assert::same((string) $response->getBody(), '{"error":"trace not found"}');
    }

    /**
     * The UI is used from its own origin only: it sends no CORS headers, and a preflight is an HTTP dump.
     */
    public function testUiHasNoCors(): void
    {
        $pipeline = self::pipeline();

        $preflight = $pipeline->handle(new ServerRequest('OPTIONS', '/api/events'), static fn(): Response => new Response(418));
        $events = $pipeline->handle(new ServerRequest('GET', '/api/events'), self::notFound(...));
        $debugger = $pipeline->handle(new ServerRequest('GET', '/api/xdebug'), self::notFound(...));

        Assert::same($preflight->getStatusCode(), 418);
        Assert::same($events->getStatusCode(), 200);
        Assert::false($events->hasHeader('Access-Control-Allow-Origin'));
        Assert::same($debugger->getStatusCode(), 200);
        Assert::false($debugger->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * The sandboxed frame of an `html` event runs the sender's scripts. A POST of the pin needs no preflight.
     */
    #[DataSet(['GET', '/api/events'])]
    #[DataSet(['POST', '/api/event/0198c2d4-7a54-7b81-9b8e-3cf7c6c3a6b1/pin'])]
    #[DataSet(['DELETE', '/api/events'])]
    public function testUiApiRefusesPageWithoutOrigin(string $method, string $path): void
    {
        $request = new ServerRequest($method, $path, ['Origin' => 'null', 'Sec-Fetch-Site' => 'cross-site']);

        $response = self::pipeline()->handle($request, static fn(): Response => new Response(418));

        Assert::same($response->getStatusCode(), 403);
        Assert::string((string) $response->getBody())->contains('"code":"origin_null"');
    }

    /**
     * A page from `file://` still sends events, Sentry ones to `/api/{project}/envelope/` among them.
     */
    public function testIngestionTakesPageWithoutOrigin(): void
    {
        $request = new ServerRequest('POST', '/api/1/envelope/', ['Origin' => 'null', 'Sec-Fetch-Site' => 'cross-site']);

        $response = self::pipeline()->handle($request, static fn(): Response => new Response(418));

        Assert::same($response->getStatusCode(), 418);
    }

    private static function pipeline(): Pipeline
    {
        return (new ObjectContainer())->get(Pipeline::class);
    }

    private static function notFound(): Response
    {
        return new Response(404);
    }
}
