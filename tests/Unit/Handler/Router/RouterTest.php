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
        yield 'Frontend Service' => [\Buggregator\Trap\Module\Frontend\Service::class];
        yield 'Frontend Event Assets' => [\Buggregator\Trap\Module\Frontend\Http\EventAssets::class];
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
     */
    public function testRouteAssertions(string $class): void
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
                static fn(\ReflectionAttribute $attr) => $attr->newInstance(),
                $routes,
            );
            $asserts = \array_map(
                static fn(\ReflectionAttribute $attr) => $attr->newInstance(),
                $asserts,
            );

            Router::assert($routes, $asserts);
            self::assertTrue(true, (string) $method . ' passed');
        }
    }

    public function testTryPrivate(): void
    {
        $router = Router::new(self::class);

        self::assertNotNull($router->match(Method::Get, '/private-route'));
    }

    public function testMatchPublicStaticRoute(): void
    {
        $router = Router::new($this);

        self::assertNotNull($route = $router->match(Method::Get, '/public-static-route'));
        self::assertSame('public-static-route-result', $route());
    }

    public function testMatchPublicStaticStaticRoute(): void
    {
        $router = Router::new(self::class);

        self::assertNotNull($route = $router->match(Method::Get, '/public-static-static-route'));
        self::assertSame('public-static-static-route-result', $route());
    }

    public function testMatchPublicStaticRegexpRoute(): void
    {
        $router = Router::new(self::class);

        self::assertNotNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        self::assertSame('123e4567-e89b-12d3-a456-426614174000', $route());
    }

    public function testMatchPublicStaticRegexpRouteWithAdditionalArgs(): void
    {
        $router = Router::new(self::class);

        self::assertNotNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        self::assertSame('123e4567-e89b-12d3-a456-426614174000', $route(id: 'test'));
    }

    public function testArgumentsCollision(): void
    {
        $router = Router::new(self::class);

        self::assertNotNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        self::assertSame('123e4567-e89b-12d3-a456-426614174000', $route(uuid: 'no-pasaran'));
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
