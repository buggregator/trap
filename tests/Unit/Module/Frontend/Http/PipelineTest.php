<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Http;

use Buggregator\Trap\Module\Frontend\Http\Pipeline;
use Internal\Container\ObjectContainer;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Testo\Assert;
use Testo\Codecov\Covers;
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

    private static function pipeline(): Pipeline
    {
        return (new ObjectContainer())->get(Pipeline::class);
    }

    private static function notFound(): Response
    {
        return new Response(404);
    }
}
