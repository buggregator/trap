<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console;

use Buggregator\Client\Proto\Frame;

interface HandlerInterface
{
    public function handle(Frame $frame): void;
}
