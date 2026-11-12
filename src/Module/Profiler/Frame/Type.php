<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Frame;

/**
 * @internal
 * @psalm-internal Buggregator
 */
enum Type: string
{
    case XHProf = 'XHProf';
    case XDebug = 'XDebug';
    case SPX = 'SPX';
    case Speedscope = 'Speedscope';
    case Pprof = 'Pprof';
}
