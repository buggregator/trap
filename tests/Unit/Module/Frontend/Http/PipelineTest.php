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

    public function testControllerStatusDoesNotGoFurther(): void
    {
        $request = new ServerRequest('GET', '/api/xdebug-traces/0190402f-7eb2-7287-82a8-897a0091f58e/summary');

        $response = self::pipeline()->handle($request, self::notFound(...));

        Assert::same($response->getStatusCode(), 404);
        Assert::true($response->hasHeader(Pipeline::FRONTEND_HEADER));
        Assert::same((string) $response->getBody(), '{"error":"trace not found"}');
    }

    public function testDebuggerIsSameOriginOnly(): void
    {
        $pipeline = self::pipeline();

        $preflight = $pipeline->handle(new ServerRequest('OPTIONS', '/api/xdebug/sessions/1/eval'), self::notFound(...));
        $status = $pipeline->handle(new ServerRequest('GET', '/api/xdebug'), self::notFound(...));
        $other = $pipeline->handle(new ServerRequest('OPTIONS', '/api/events'), self::notFound(...));

        Assert::same($preflight->getStatusCode(), 403);
        Assert::false($preflight->hasHeader('Access-Control-Allow-Origin'));
        Assert::same($status->getStatusCode(), 200);
        Assert::false($status->hasHeader('Access-Control-Allow-Origin'));
        Assert::same($other->getHeaderLine('Access-Control-Allow-Origin'), '*');
        Assert::string($other->getHeaderLine('Access-Control-Allow-Methods'))->contains('PATCH');
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
