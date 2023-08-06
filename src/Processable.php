<?php

declare(strict_types=1);

namespace Buggregator\Trap;

/**
 * Must be processed in a main loop.
 *
 * @internal
 */
interface Processable
{
    public function process(): void;
}
