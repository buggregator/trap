<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Router\Attribute;

use Buggregator\Trap\Handler\Router\Method;

/**
 * @internal
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AssertRouteSuccess extends AssertRoute
{
    public function __construct(
        Method $method,
        string $path,
        public readonly ?array $args = null,
    ) {
        parent::__construct($method, $path);
    }
}
