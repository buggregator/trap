<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Handler\Router;

use Buggregator\Trap\Module\Frontend\UiControllers;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRoute as AssertAttribute;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteFail;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess;
use Buggregator\Trap\Server\Handler\Router\Attribute\QueryParam;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Attribute\Route as RouteAttribute;
use Buggregator\Trap\Server\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Handler\Router\RouteDto;
use Buggregator\Trap\Server\Handler\Router\Router;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
final class RouterTest
{
    public static function routedClassesProvider(): iterable
    {
        yield 'self' => [self::class];

        $controllers = (new ObjectContainer())->get(UiControllers::class);
        foreach ([...$controllers->api(), ...$controllers->assets()] as $controller) {
            yield $controller::class => [$controller::class];
        }
    }

    public static function collidingObjectsProvider(): iterable
    {
        $staticRoute = static fn(): object => new class {
            #[StaticRoute(Method::Get, 'api/events')]
            public function list(): string
            {
                return 'static';
            }
        };

        yield 'the same object twice' => [[$self = new self(), $self]];
        yield 'two objects of the same class' => [[new self(), new self()]];
        yield 'the same static path' => [[$staticRoute(), new class {
            #[StaticRoute(Method::Get, 'api/events')]
            public function list(): string
            {
                return 'other';
            }
        }]];
        yield 'the same pattern' => [[
            new class {
                #[RegexpRoute(Method::Get, '#^api/event/(?<uuid>[a-f0-9-]++)$#i')]
                public function show(string $uuid): string
                {
                    return $uuid;
                }
            },
            new class {
                #[RegexpRoute(Method::Get, '#^api/event/(?<uuid>[a-f0-9-]++)$#i')]
                public function show(string $uuid): string
                {
                    return $uuid;
                }
            },
        ]];
        $pattern = new class {
            #[RegexpRoute(Method::Get, '#^api/(?<name>[a-z]++)$#')]
            public function any(string $name): string
            {
                return $name;
            }
        };
        yield 'a pattern before a static path it matches' => [[$pattern, $staticRoute()]];
        yield 'a static path before a pattern matching it' => [[$staticRoute(), $pattern]];
    }

    #[StaticRoute(Method::Get, 'public-static-static-route')]
    public static function publicStaticStaticRoute(): string
    {
        return 'public-static-static-route-result';
    }

    #[AssertRouteSuccess(Method::Delete, 'item/f00', ['uuid' => 'f00'])]
    #[AssertRouteSuccess(Method::Delete, 'item/fzzzzzzzzz', ['uuid' => 'f'])]
    #[AssertRouteFail(Method::Get, 'item/f00')]
    #[RegexpRoute(Method::Delete, '#^item/(?<uuid>[a-f0-9-]++)#i')]
    public static function publicStaticRegexpRoute(string $uuid): string
    {
        return $uuid;
    }

    #[DataProvider('routedClassesProvider')]
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

            Router::assert($method, $routes, $asserts);
            Assert::true(true, (string) $method . ' passed');
        }
    }

    public function testTryPrivate(): void
    {
        $router = Router::new(self::class);

        Assert::notNull($router->match(Method::Get, '/private-route'));
    }

    public function testMatchPublicStaticRoute(): void
    {
        $router = Router::new($this);

        Assert::notNull($route = $router->match(Method::Get, '/public-static-route'));
        Assert::same($route(), 'public-static-route-result');
    }

    public function testMatchPublicStaticStaticRoute(): void
    {
        $router = Router::new(self::class);

        Assert::notNull($route = $router->match(Method::Get, '/public-static-static-route'));
        Assert::same($route(), 'public-static-static-route-result');
    }

    public function testMatchPublicStaticRegexpRoute(): void
    {
        $router = Router::new(self::class);

        Assert::notNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        Assert::same($route(), '123e4567-e89b-12d3-a456-426614174000');
    }

    public function testMatchPublicStaticRegexpRouteWithAdditionalArgs(): void
    {
        $router = Router::new(self::class);

        Assert::notNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        Assert::same($route(id: 'test'), '123e4567-e89b-12d3-a456-426614174000');
    }

    public function testArgumentsCollision(): void
    {
        $router = Router::new(self::class);

        Assert::notNull($route = $router->match(Method::Delete, '/item/123e4567-e89b-12d3-a456-426614174000'));
        Assert::same($route(uuid: 'no-pasaran'), '123e4567-e89b-12d3-a456-426614174000');
    }

    public function testComposedRouterCallsMethodsOfTheirObjects(): void
    {
        $other = new class('other') {
            public function __construct(
                private readonly string $name,
            ) {}

            #[RegexpRoute(Method::Get, '#^other/(?<id>\d++)$#')]
            public function show(string $id): string
            {
                return "$this->name:$id";
            }
        };

        $router = Router::compose($this, $other);

        Assert::same($router->match(Method::Get, '/public-static-route')(), 'public-static-route-result');
        Assert::same($router->match(Method::Get, '/other/42')(), 'other:42');
        Assert::same($router->match(Method::Delete, '/item/f00')(), 'f00');
        Assert::null($router->match(Method::Get, '/unknown'));
    }

    public function testComposedRouterKeepsOrderOfObjectsThenMethods(): void
    {
        $first = new class {
            #[StaticRoute(Method::Get, 'b')]
            public function b(): void {}

            #[StaticRoute(Method::Get, 'a')]
            public function a(): void {}
        };
        $second = new class {
            #[RegexpRoute(Method::Get, '#^c#')]
            public function c(): void {}
        };

        $router = Router::compose($first, $second);
        $order = (new \ReflectionProperty(Router::class, 'routes'))->getValue($router)[Method::Get->value];

        Assert::same(
            \array_map(static fn(RouteDto $route): string => $route->method->name, $order),
            ['b', 'a', 'c'],
        );
        Assert::same($order[0]->object, $first);
        Assert::same($order[2]->object, $second);
    }

    public function testResolveFindsMethodWithoutCallingIt(): void
    {
        $router = Router::compose($this);

        Assert::same($router->resolve(Method::Get, '/private-route?x=1')?->name, 'privateRoute');
        Assert::null($router->resolve(Method::Post, '/private-route'));
    }

    public function testQueryParamReadsNameFromAttribute(): void
    {
        $router = Router::compose(new class {
            #[StaticRoute(Method::Get, 'top')]
            public function top(#[QueryParam(name: 'n')] int $limit = 5, #[QueryParam] string $metric = 'wt'): string
            {
                return "$limit:$metric";
            }
        });

        Assert::same($router->match(Method::Get, '/top?n=3&metric=cpu')(), '3:cpu');
        Assert::same($router->match(Method::Get, '/top?limit=3')(), '5:wt');
    }

    public function testComposeRejectsObjectWithoutRoutes(): void
    {
        Expect::exception(\LogicException::class)->withMessageContaining('has no routes');

        Router::compose($this, new \stdClass());
    }

    /**
     * @param list<object> $objects
     */
    #[DataProvider('collidingObjectsProvider')]
    public function testComposeRejectsCollidingRoutes(array $objects): void
    {
        Expect::exception(\LogicException::class)->withMessageContaining('collides with the route of');

        Router::compose(...$objects);
    }

    #[StaticRoute(Method::Get, 'public-static-route')]
    public function publicStaticRoute(): string
    {
        return 'public-static-route-result';
    }

    #[AssertRouteSuccess(Method::Get, '/private-route')]
    #[AssertRouteSuccess(Method::Get, 'private-route')]
    #[AssertRouteFail(Method::Post, '/private-route')]
    #[StaticRoute(Method::Get, 'private-route')]
    private function privateRoute(): never
    {
        throw new \LogicException('This method should not be called.');
    }
}
