<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Http;

use Buggregator\Trap\Module\Frontend\Http\Pipeline;
use Buggregator\Trap\Server\Handler\Http\Handler\Fallback;
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
    public function testUiResponseIsNotDumped(): void
    {
        $response = self::pipeline()->handle(new ServerRequest('GET', '/api/version'), self::notFound(...));

        Assert::same($response->getStatusCode(), 200);
        Assert::true($response->hasHeader(Pipeline::FRONTEND_HEADER));
        Assert::true($response->hasHeader(Fallback::NO_DUMP_HEADER));
    }

    public function testRequestUnknownToUiGoesFurther(): void
    {
        $response = self::pipeline()->handle(new ServerRequest('POST', '/some/app/endpoint'), self::notFound(...));

        Assert::same($response->getStatusCode(), 404);
        Assert::false($response->hasHeader(Fallback::NO_DUMP_HEADER));
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
