<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Handler\Router;

use Buggregator\Trap\Handler\Router\Attribute\AssertRoute as AssertAttribute;
use Buggregator\Trap\Handler\Router\Attribute\AssertRouteFail;
use Buggregator\Trap\Handler\Router\Attribute\AssertRouteSuccess;
use Buggregator\Trap\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Handler\Router\Attribute\Route as RouteAttribute;
use Buggregator\Trap\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Handler\Router\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public static function routedClassesProvider(): iterable
    {
        yield 'self' => [self::class];
        yield 'Frontend Service' => [\Buggregator\Trap\Sender\Frontend\Service::class];
        yield 'Frontend Event Assets' => [\Buggregator\Trap\Sender\Frontend\Http\EventAssets::class];
    }

    #[StaticRoute(Method::Get, '/public-static-static-route')]
    public static function publicStaticStaticRoute(): string
    {
        return 'public-static-static-route-result';
    }

    #[AssertRouteSuccess(Method::Delete, '/item/f00', ['uuid' => 'f00'])]
    #[AssertRouteSuccess(Method::Delete, '/item/fzzzzzzzzz', ['uuid' => 'f'])]
    #[AssertRouteFail(Method::Get, '/item/f00')]
    #[RegexpRoute(Method::Delete, '#^/item/(?<uuid>[a-f0-9-]++)#i')]
    public static function publicStaticRegexpRoute(string $uuid): string
    {
        return $uuid;
    }

    /**
     * @dataProvider routedClassesProvider
     *
     * @test
     */
    public function route_assertions(string $class): void
    {
        // Find all public methods with #[Route] and #[AsserRoute] attributes
        foreach ((new \ReflectionClass($class))->getMethods() as $method) {
            if (empty($routes = $method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF))) {
                continue;
            }
            if (empty($asserts = $method->getAttributes(AssertAttribute::class, \ReflectionAttribute::IS_INSTANCEOF))) {
                continue;
            }

            $routes = \array_map(
                static fn (\ReflectionAttribute $attr) => $attr->newInstance(),
                $routes,
            );
            $asserts = \array_map(
                static fn (\ReflectionAttribute $attr) => $attr->newInstance(),
                $asserts,
            );

            Router::assert($routes, $asserts);
            $this->assertTrue(true, (string) $method . ' passed');
        }
    }

    /**
     * @test
     */
    public function try_private(): void
    {
        $router = Router::new(self::class);

        $this->assertNotNull($router->match(Method::Get, '/private-route'));
    }

    /**
     * @test
     */
    public function match_public_static_route(): void
    {
        $router = Router::new($this);

        $this->assertNotNull($route = $router->match(Method::Get, '/public-static-route'));
        $this->assertSame('public-static-route-result', $route());
    }

    /**
     * @test
     */
    public function match_public_static_static_route(): void
    {
        $router = Router::new(self::class);

        $this->assertNotNull($route = $router->match(Method::Get, '/public-static-static-route'));
        $this->assertSame('public-static-static-route-result', $route());
    }

    /**
     * @test
     */
    public function match_public_static_regexp_route(): void
    {
        $router = Router::new(self::class);

        $this->assertNotNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $route());
    }

    /**
     * @test
     */
    public function match_public_static_regexp_route_with_additional_args(): void
    {
        $router = Router::new(self::class);

        $this->assertNotNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $route(id: 'test'));
    }

    /**
     * @test
     */
    public function arguments_collision(): void
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

    #[AssertRouteSuccess(Method::Get, '/private-route')]
    #[AssertRouteFail(Method::Post, '/private-route')]
    #[AssertRouteFail(Method::Get, 'private-route')]
    #[StaticRoute(Method::Get, '/private-route')]
    private function privateRoute(): never
    {
        throw new \LogicException('This method should not be called.');
    }
}
