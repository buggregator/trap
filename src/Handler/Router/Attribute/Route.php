<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Router\Attribute;

use Buggregator\Trap\Handler\Router\Method;

/**
 * @internal
 */
abstract class Route
{
    public function __construct(
        public Method $method,
    ) {}
}
