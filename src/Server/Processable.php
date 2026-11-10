<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server;

/**
 * Must be processed in a main loop outside a Fiber
 *
 * @internal
 */
interface Processable
{
    public function process(): void;
}
