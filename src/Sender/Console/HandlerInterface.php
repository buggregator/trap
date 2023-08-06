<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console;

use Buggregator\Trap\Proto\Frame;

/**
 * @internal
 */
interface HandlerInterface
{
    public function handle(Frame $frame): void;
}
