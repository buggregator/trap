<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Handler\Http\Middleware;

use Buggregator\Trap\Server\Handler\Http\Middleware\Resources;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Resources::class)]
#[Test]
final class ResourcesTest
{
    public function testServesFavicon(): void
    {
        $response = (new Resources())->handle(new ServerRequest('GET', '/favicon.ico'), self::next(...));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Content-Type'), 'image/x-icon');
    }

    public function testResourceTrapDoesNotHaveGoesOn(): void
    {
        $response = (new Resources())->handle(new ServerRequest('GET', '/resources/app.css'), self::next(...));

        Assert::same($response->getStatusCode(), 418);
    }

    private static function next(): ResponseInterface
    {
        return new Response(418);
    }
}
