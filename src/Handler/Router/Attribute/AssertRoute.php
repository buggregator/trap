<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Router\Attribute;

use Buggregator\Trap\Handler\Router\Method;

/**
 * @internal
 */
abstract class AssertRoute
{
    public function __construct(
        public Method $method,
        public string $path,
    ) {}
}
