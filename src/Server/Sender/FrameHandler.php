<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

use Buggregator\Trap\Server\Proto\Frame;

/**
 * @internal
 */
interface FrameHandler
{
    public function handle(Frame $frame): void;
}
