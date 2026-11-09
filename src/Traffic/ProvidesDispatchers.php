<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic;

use Buggregator\Trap\Application\Module\Module;

/**
 * A module that detects and handles its own protocol on the socket servers.
 *
 * @internal
 */
interface ProvidesDispatchers extends Module
{
    /**
     * Each dispatcher is created anew for every inspector.
     *
     * @return list<class-string<Dispatcher>>
     */
    public function dispatchers(): array;
}
