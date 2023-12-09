<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Proto\Frame;

/**
 * @internal
 */
interface FrameHandler
{
    public function handle(Frame $frame): void;
}
