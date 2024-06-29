<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Router;

use Buggregator\Trap\Handler\Router\Attribute\AssertRoute as AssertAttribute;
use Buggregator\Trap\Handler\Router\Attribute\QueryParam as RouteQueryParam;
use Buggregator\Trap\Handler\Router\Attribute\Route as RouteAttribute;
use Buggregator\Trap\Handler\Router\Exception\AssertRouteFailed;

/**
 * @internal
 */
final class Router
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    /** @var null|object Null for routes defined in static methods */
    private ?object $object = null;

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

                if (!$found || $assertion->args === null) {
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
     * Convert query parameter to the specified type.
     */
    public static function convertQueryParam(
        \ReflectionParameter $param,
        array $params,
    ): mixed {
        $name = $param->getName();
        $type = $param->getType();

        $queryName = $queryParam->name ?? $name;
        if (!isset($params[$queryName])) {
            $param->isDefaultValueAvailable() or throw new \InvalidArgumentException(\sprintf(
                'Query parameter `%s` is required.',
                $queryName,
            ));

            return $param->getDefaultValue();
        }

        $value = $params[$queryName];
        if ($type === null) {
            return $value;
        }

        foreach (($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type]) as $t) {
            $typeString = \ltrim($t?->getName() ?? '', '?');
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
        $path = $components['path'] ?? '';
        $query = $components['query'] ?? '';

        foreach ($this->routes[$method->value] as $route) {
            $rr = $route->route;
            /** @psalm-suppress ArgumentTypeCoercion */
            $match = match ($rr::class) {
                Attribute\StaticRoute::class => $path === (string) $rr->path,
                Attribute\RegexpRoute::class => \preg_match((string) $rr->regexp, $path, $matches) === 1
                    ? \array_filter($matches, '\is_string', \ARRAY_FILTER_USE_KEY)
                    : false,
                default => throw new \LogicException(\sprintf(
                    'Route type `%s` is not supported.',
                    $route::class,
                )),
            };

            if ($match === false) {
                continue;
            }

            $get = [];
            \parse_str($query, $get);

            // Prepare callable
            $object = $this->object;
            return match(true) {
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
     * @param array<non-empty-string, mixed> $args Arguments for the URI path
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
                    $filteredArgs[$name] = self::convertQueryParam($param, $params);
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
        $new = clone $this;
        $new->object = $object;
        return $new;
    }
}
