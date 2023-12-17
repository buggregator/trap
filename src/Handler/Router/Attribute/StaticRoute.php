<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Router\Attribute;

use Buggregator\Trap\Handler\Router\Method;

/**
 * @internal
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class StaticRoute extends Route
{
    public function __construct(
        Method $method,
        public string|\Stringable $path,
    ) {
        parent::__construct($method);
    }
}
