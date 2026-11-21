<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Server\Handler\Router\Exception\InvalidRouteArgument;

/**
 * Tells a bad request parameter, as `?project[]=x` for a string or a missing one, from a failure of the route code.
 *
 * @internal
 */
final class BadRouteArgument
{
    /**
     * @param \ReflectionMethod|null $route The method of the route called.
     */
    public static function isOf(\Throwable $e, ?\ReflectionMethod $route): bool
    {
        if ($e instanceof InvalidRouteArgument) {
            return true;
        }

        if (!$e instanceof \TypeError || $route === null) {
            return false;
        }

        // Thrown on entering the route method, not by the code in it
        $frame = $e->getTrace()[0] ?? [];
        $name = "{$route->class}::{$route->name}()";

        return ($frame['function'] ?? null) === $route->name
            && ($frame['class'] ?? null) === $route->class
            && (\str_starts_with($e->getMessage(), "{$name}: Argument #")
                || $e instanceof \ArgumentCountError
                && \str_starts_with($e->getMessage(), "Too few arguments to function {$name}"));
    }
}
