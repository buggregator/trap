<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Router;

/**
 * @internal
 */
enum Method: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Delete = 'DELETE';
    case Patch = 'PATCH';
    case Head = 'HEAD';
    case Options = 'OPTIONS';
    case Trace = 'TRACE';
    case Connect = 'CONNECT';

    public static function fromString(string $method): self
    {
        return self::from(\strtoupper($method));
    }
}
