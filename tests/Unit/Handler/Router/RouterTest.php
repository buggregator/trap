<?php

namespace Buggregator\Trap\Tests\Unit\Handler\Router;

use Buggregator\Trap\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Handler\Router\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testTryPrivate(): void
    {
        $router = Router::new(self::class);

        $this->assertNull($router->match(Method::Get, '/private-route'));
    }

    public function testMatchPublicStaticRoute(): void
    {
        $router = Router::new($this);

        $this->assertNotNull($route = $router->match(Method::Get, '/public-static-route'));
        $this->assertSame('public-static-route-result', $route());
    }

    public function testMatchPublicStaticStaticRoute(): void
    {
        $router = Router::new(self::class);

        $this->assertNotNull($route = $router->match(Method::Get, '/public-static-static-route'));
        $this->assertSame('public-static-static-route-result', $route());
    }

    public function testMatchPublicStaticRegexpRoute(): void
    {
        $router = Router::new(self::class);

        $this->assertNotNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $route());
    }

    public function testMatchPublicStaticRegexpRouteWithAdditionalArgs(): void
    {
        $router = Router::new(self::class);

        $this->assertNotNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $route(id: 'test'));
    }

    public function testArgumentsCollision(): void
    {
        $router = Router::new(self::class);

        $this->assertNotNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $route(uuid: 'no-pasaran'));
    }

    #[StaticRoute(Method::Get, '/public-static-route')]
    public function publicStaticRoute(): string
    {
        return 'public-static-route-result';
    }

    #[StaticRoute(Method::Get, '/public-static-static-route')]
    public static function publicStaticStaticRoute(): string
    {
        return 'public-static-static-route-result';
    }

    #[RegexpRoute(Method::Delete, '#^/item/(?<uuid>[a-f0-9-]++)#i')]
    public static function publicStaticRegexpRoute(string $uuid): string
    {
        return $uuid;
    }

    #[StaticRoute(Method::Get, '/private-route')]
    private function privateRoute(): never
    {
        throw new \LogicException('This method should not be called.');
    }
}
