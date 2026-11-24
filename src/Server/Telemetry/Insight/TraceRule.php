<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Insight;

use Buggregator\Trap\Server\Telemetry\Trace;

/**
 * A check of a stored trace for a problem in the code that sent it, such as a query repeated in a loop.
 *
 * @internal
 */
interface TraceRule
{
    /**
     * @return list<Finding> In any order: {@see TraceInsights} sorts them.
     */
    public function check(Trace $trace): array;
}
