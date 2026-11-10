<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server;

/**
 * The implementation can be canceled safely.
 *
 * @internal
 */
interface Cancellable
{
    public function cancel(): void;
}
