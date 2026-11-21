<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Handler\Router\Exception;

/**
 * A request parameter the route can't take: missing, or of a wrong type.
 *
 * @internal
 */
final class InvalidRouteArgument extends \InvalidArgumentException {}
