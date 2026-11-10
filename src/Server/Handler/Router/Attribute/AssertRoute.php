<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Handler\Router\Attribute;

use Buggregator\Trap\Server\Handler\Router\Method;

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
