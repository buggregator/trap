<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Otlp\Trace;

/**
 * Values match the OTLP `Status.StatusCode` enum.
 *
 * @internal
 * @psalm-internal Buggregator
 */
enum StatusCode: int
{
    case Unset = 0;
    case Ok = 1;
    case Error = 2;
}
