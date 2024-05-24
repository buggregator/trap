<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Router;

use Buggregator\Trap\Handler\Router\Attribute\Route;

/**
 * @internal
 */
final class RouteDto
{
    public function __construct(
        public \ReflectionMethod $method,
        public Route $route,
    ) {}
}
