<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Router\Attribute;

/**
 * Request query parameter.
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class QueryParam
{
    /**
     * @param string|null $name Query parameter name. If not provided, the parameter name from
     *        the method signature is used.
     */
    public function __construct(
        public readonly ?string $name = null,
    ) {}
}
