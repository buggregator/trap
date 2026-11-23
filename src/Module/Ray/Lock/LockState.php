<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray\Lock;

/**
 * Where a `ray()->pause()` stands. The UI reads a released state from `content.state` of the `create_lock`
 * payload; a payload without it is active.
 *
 * @internal
 */
enum LockState: string
{
    case Active = 'active';
    case Continued = 'continued';
    case Stopped = 'stopped';
}
