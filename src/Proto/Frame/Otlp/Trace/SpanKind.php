<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Otlp\Trace;

/**
 * Values match the OTLP `Span.SpanKind` enum.
 *
 * @internal
 * @psalm-internal Buggregator
 */
enum SpanKind: int
{
    case Unspecified = 0;
    case Internal = 1;
    case Server = 2;
    case Client = 3;
    case Producer = 4;
    case Consumer = 5;
}
