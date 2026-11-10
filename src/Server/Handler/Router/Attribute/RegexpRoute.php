<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Handler\Router\Attribute;

use Buggregator\Trap\Server\Handler\Router\Method;

/**
 * @internal
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RegexpRoute extends Route
{
    /**
     * @param non-empty-string|\Stringable $regexp
     */
    public function __construct(
        Method $method,
        public string|\Stringable $regexp,
    ) {
        parent::__construct($method);
    }
}
