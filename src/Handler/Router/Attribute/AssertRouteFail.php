<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Router\Attribute;

/**
 * @internal
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AssertRouteFail extends AssertRoute {}
