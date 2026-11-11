<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler;

/**
 * Encodings a {@see Profile} can be written in.
 *
 * Excimer gives Speedscope and Collapsed natively and converts to XHProf; XHProf gives XHProf only:
 * its call pairs can't be turned back into the stacks the other formats need.
 *
 * @api
 */
enum Format: string
{
    /**
     * Speedscope JSON ({@link https://www.speedscope.app/}).
     */
    case Speedscope = 'speedscope';

    /**
     * Collapsed (folded) stacks: one `frame;frame;frame count` line per stack, as flame graph tools read it.
     */
    case Collapsed = 'collapsed';

    /**
     * JSON of the XHProf array: `{"caller==>callee": {"ct": …, "wt": …, "cpu"?, "mu"?, "pmu"?}}`.
     */
    case XHProf = 'xhprof';
}
