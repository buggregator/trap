<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Handler\Router\Attribute;

use Buggregator\Trap\Server\Handler\Router\Method;

/**
 * @internal
 */
abstract class Route
{
    public function __construct(
        public Method $method,
    ) {}
}
