<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Profiler;

/**
 * @internal
 * @psalm-internal Buggregator
 */
enum Type: string
{
    case XHProf = 'XHProf';
    case XDebug = 'XDebug';
    case SPX = 'SPX';
}
