<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Handler\Router;

use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRoute as AssertAttribute;
use Buggregator\Trap\Server\Handler\Router\Attribute\QueryParam as RouteQueryParam;
use Buggregator\Trap\Server\Handler\Router\Attribute\Route as RouteAttribute;
use Buggregator\Trap\Server\Handler\Router\Exception\AssertRouteFailed;

/**
 * @internal
 */
final class Router
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * @param array<non-empty-string, list<RouteDto>> $routes Indexed by {@see Method}: Method => RouteDto[]
     */
    private function __construct(
        private readonly array $routes,
    ) {}

    /**
     * @param array<RouteAttribute> $routes
     * @param array<AssertAttribute> $assertions
     * @throws AssertRouteFailed
     */
    public static function assert(\ReflectionMethod $method, array $routes, array $assertions): void
    {
        $index = \array_fill_keys(\array_column(Method::cases(), 'value'), []);
        foreach ($routes as $route) {
            $route instanceof RouteAttribute or throw new \InvalidArgumentException(\sprintf(
                'Routes expected to be `%s` instances, `%s` given.',
                RouteAttribute::class,
                $route::class,
            ));

            $index[$route->method->value][] = new RouteDto(
                method: $method,
                route: $route,
            );
        }

        $self = new self($index);

        /** @var list<non-empty-string> $fails */
        $fails = [];
        $mockMethod = new \ReflectionMethod(self::class, 'doNothing');
        foreach ($assertions as $assertion) {
            $assertion instanceof AssertAttribute or throw new \InvalidArgumentException(\sprintf(
                'Assertions expected to be `%s` instances, `%s` given.',
                AssertAttribute::class,
                $assertion::class,
            ));

            try {
                $handler = $self->match($assertion->method, $assertion->path, $mockMethod);
            } catch (\Throwable $e) {
                throw new AssertRouteFailed(\sprintf(
                    '> Failed to match route -> %s `%s`.',
                    $assertion->method->value,
                    $assertion->path,
                ), 0, $e);
            }
            $found = $handler !== null;
            // Mustn't be matched
            if ($assertion::class === Attribute\AssertRouteFail::class) {
                $found and $fails[] = \sprintf(
                    '> Shouldn\'t be matched -> %s `%s`. Parsed arguments: %s',
                    $assertion->method->value,
                    $assertion->path,
                    \print_r($handler(), true),
                );

                continue;
            }

            // Must be matched
            if ($assertion::class === Attribute\AssertRouteSuccess::class) {
                $found or $fails[] = \sprintf(
                    '> Should be matched -> %s `%s`.',
                    $assertion->method->value,
                    $assertion->path,
                );

                if ($handler === null || $assertion->args === null) {
                    continue;
                }

                // Check params
                /** @var array<non-empty-string, mixed> $args */
                $args = $handler();
                if ($args !== $assertion->args) {
                    $fails[] = \sprintf(
                        "> Arguments mismatch for -> %s `%s`.\n    Expected: %s\n    Actual: %s",
                        $assertion->method->value,
                        $assertion->path,
                        \print_r($assertion->args, true),
                        \print_r($args, true),
                    );
                }
            }
        }

        if ($fails === []) {
            return;
        }

        throw new AssertRouteFailed("Route assertions failed.\n" . \implode("\n", $fails));
    }

    /**
     * @param class-string|object $classOrObject Class name or object to create router for.
     *        Specify an object to associate router with an object. It is important for routes defined in
     *        non-static methods.
     *
     * @throws \Exception
     */
    public static function new(string|object $classOrObject): self
    {
        return \is_object($classOrObject)
            ? self::newStatic($classOrObject::class)->withObject($classOrObject)
            : self::newStatic($classOrObject);
    }

    /**
     * Create a router over the routes of several objects.
     *
     * A request is matched against the routes in the order of the objects, then of their methods.
     *
     * @throws \LogicException When an object has no routes, or routes of two objects collide: the same method
     *         and path or pattern, or a static path matched by a pattern.
     * @throws \Exception
     */
    public static function compose(object ...$objects): self
    {
        $index = \array_fill_keys(\array_column(Method::cases(), 'value'), []);
        foreach ($objects as $object) {
            foreach (self::new($object)->routes as $method => $routes) {
                foreach ($routes as $route) {
                    foreach ($index[$method] as $known) {
                        self::assertNoCollision($known, $route);
                    }
                }

                // Added after the check: routes of one object may overlap, as they do in one router
                \array_push($index[$method], ...$routes);
            }
        }

        return new self($index);
    }

    /**
     * Convert query parameter to the specified type.
     *
     * @param string|null $queryName Query parameter name; the method parameter name when null.
     */
    public static function convertQueryParam(
        \ReflectionParameter $param,
        array $params,
        ?string $queryName = null,
    ): mixed {
        $name = $param->getName();
        $type = $param->getType();

        $queryName ??= $name;
        if (!isset($params[$queryName])) {
            $param->isDefaultValueAvailable() or throw new \InvalidArgumentException(\sprintf(
                'Query parameter `%s` is required.',
                $queryName,
            ));

            return $param->getDefaultValue();
        }

        /** @var mixed $value */
        $value = $params[$queryName];
        if ($type === null) {
            return $value;
        }

        foreach (($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type]) as $t) {
            $typeString = $t instanceof \ReflectionNamedType ? \ltrim($t->getName(), '?') : '';
            switch (true) {
                case $typeString === 'mixed':
                    return $value;
                case $typeString === 'array':
                    return (array) $value;
                case $typeString === 'int':
                    return (int) (\is_array($value)
                        ? throw new \InvalidArgumentException(
                            \sprintf(
                                'Query parameter `%s` must be an integer, array given.',
                                $queryName,
                            ),
                        )
                        : $value);
                case $typeString === 'float':
                    return (float) (\is_array($value)
                        ? throw new \InvalidArgumentException(
                            \sprintf(
                                'Query parameter `%s` must be an integer, array given.',
                                $queryName,
                            ),
                        )
                        : $value);
                case $typeString === 'string':
                    return (string) (\is_array($value)
                        ? throw new \InvalidArgumentException(
                            \sprintf(
                                'Query parameter `%s` must be a string, array given.',
                                $queryName,
                            ),
                        )
                        : $value);
                default:
                    continue 2;
            }
        }

        throw new \InvalidArgumentException(\sprintf(
            'Query parameter `%s` must be of type `%s`, `%s` given.',
            $queryName,
            $type,
            \gettype($value),
        ));
    }

    /**
     * Find a route for specified method and path.
     *
     * @param \ReflectionMethod|null $mock Mock method to use instead of the real one. The real method will be used
     *        for arguments resolution.
     *
     * @return null|callable(mixed...): mixed Returns null if no route matches
     *
     * @throws \Exception
     */
    public function match(Method $method, string $uri, ?\ReflectionMethod $mock = null): ?callable
    {
        $components = \parse_url($uri);
        $path = \ltrim($components['path'] ?? '', '/');
        $query = $components['query'] ?? '';

        foreach ($this->routes[$method->value] as $route) {
            $match = self::matchPath($route->route, $path);

            if ($match === false) {
                continue;
            }

            $get = [];
            \parse_str($query, $get);

            // Prepare callable
            $object = $route->object;
            return match (true) {
                \is_callable($match) => $match,
                default => static fn(mixed ...$args): mixed => ($mock ?? $route->method)->invokeArgs(
                    ($mock ?? $route->method)->isStatic()
                        ? null
                        : $object,
                    self::resolveArguments(
                        $route->method,
                        $object,
                        \array_merge($args, \is_array($match) ? $match : []),
                        $get,
                    ),
                ),
            };
        }

        return null;
    }

    /**
     * Find the method that handles the specified method and path, without calling it.
     */
    public function resolve(Method $method, string $uri): ?\ReflectionMethod
    {
        $path = \ltrim(\parse_url($uri)['path'] ?? '', '/');

        foreach ($this->routes[$method->value] as $route) {
            if (self::matchPath($route->route, $path) !== false) {
                return $route->method;
            }
        }

        return null;
    }

    /**
     * @return array<array-key, string>|bool False when the path doesn't match; the named groups of a pattern
     */
    private static function matchPath(RouteAttribute $route, string $path): array|bool
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        return match ($route::class) {
            Attribute\StaticRoute::class => $path === (string) $route->path,
            Attribute\RegexpRoute::class => \preg_match((string) $route->regexp, $path, $matches) === 1
                ? \array_filter($matches, '\is_string', \ARRAY_FILTER_USE_KEY)
                : false,
            default => throw new \LogicException(\sprintf(
                'Route type `%s` is not supported.',
                $route::class,
            )),
        };
    }

    /**
     * Patterns are compared as strings: two different patterns matching the same path are not detected,
     * except for a static path.
     *
     * @throws \LogicException
     */
    private static function assertNoCollision(RouteDto $known, RouteDto $new): void
    {
        $a = $known->route;
        $b = $new->route;
        $collides = match (true) {
            $a instanceof Attribute\StaticRoute && $b instanceof Attribute\StaticRoute
                => (string) $a->path === (string) $b->path,
            $a instanceof Attribute\RegexpRoute && $b instanceof Attribute\RegexpRoute
                => (string) $a->regexp === (string) $b->regexp,
            $a instanceof Attribute\RegexpRoute && $b instanceof Attribute\StaticRoute
                => self::matchPath($a, (string) $b->path) !== false,
            $a instanceof Attribute\StaticRoute && $b instanceof Attribute\RegexpRoute
                => self::matchPath($b, (string) $a->path) !== false,
            default => false,
        };

        $collides and throw new \LogicException(\sprintf(
            'Route %s of `%s::%s()` collides with the route of `%s::%s()`.',
            $b->method->value,
            $new->method->class,
            $new->method->name,
            $known->method->class,
            $known->method->name,
        ));
    }

    /**
     * Create a new instance of Router for specified class. To associate router with an object use {@see withObject()}.
     *
     * @param class-string $class
     *
     * @throws \Exception
     */
    private static function newStatic(string $class): self
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $routes = self::collectRoutes($class);

        if (empty($routes)) {
            throw new \LogicException(\sprintf(
                'Class `%s` has no routes. Use `#[%s]` family of attributes to define routes.',
                $class,
                RouteAttribute::class,
            ));
        }

        // Prepare an indexed array of routes
        $index = \array_fill_keys(\array_column(Method::cases(), 'value'), []);
        foreach ($routes as $route) {
            $index[$route->route->method->value][] = $route;
        }

        return self::$cache[$class] = new self(
            routes: $index,
        );
    }

    /**
     * Collect routes from class.
     *
     * @param class-string $class
     *
     * @return list<RouteDto>
     *
     * @throws \ReflectionException
     */
    private static function collectRoutes(string $class): array
    {
        /** @var list<RouteDto> $result */
        $result = [];

        // Find all public methods with #[Route] attribute
        foreach ((new \ReflectionClass($class))->getMethods() as $method) {
            if (empty($attrs = $method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF))) {
                continue;
            }

            foreach ($attrs as $attr) {
                $result[] = new RouteDto(
                    method: $method,
                    route: $attr->newInstance(),
                );
            }
        }

        return $result;
    }

    /**
     * Resolve arguments for the route method.
     *
     * @param array<array-key, mixed> $args Arguments for the URI path
     * @param array<array-key, mixed> $params Query parameters (GET)
     *
     * @throws \Throwable
     */
    private static function resolveArguments(
        \ReflectionMethod $method,
        ?object $object,
        array $args,
        array $params,
    ): array {
        if ($method->isVariadic()) {
            $filteredArgs = $args;
        } else {
            /** @var array<non-empty-string, mixed> $filteredArgs Filter args */
            $filteredArgs = [];
            foreach ($method->getParameters() as $param) {
                $name = $param->getName();

                /** @var null|RouteQueryParam $queryParam */
                $queryParam = ($param->getAttributes(RouteQueryParam::class)[0] ?? null)?->newInstance();
                if ($queryParam !== null) {
                    /** @psalm-suppress MixedAssignment */
                    $filteredArgs[$name] = self::convertQueryParam($param, $params, $queryParam->name);
                    continue;
                }
                if (isset($args[$name])) {
                    /** @psalm-suppress MixedAssignment */
                    $filteredArgs[$name] = $args[$name];
                }
            }
        }

        return $filteredArgs;
    }

    private static function doNothing(mixed ...$args): array
    {
        return $args;
    }

    /**
     * Associate router with an object.
     */
    private function withObject(object $object): self
    {
        return new self(\array_map(
            static fn(array $routes): array => \array_map(
                static fn(RouteDto $route): RouteDto => new RouteDto($route->method, $route->route, $object),
                $routes,
            ),
            $this->routes,
        ));
    }
}
