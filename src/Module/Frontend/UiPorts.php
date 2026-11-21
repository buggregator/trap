<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

/**
 * Ports of a module for the setup guide of the UI, see {@see ProvidesUiPorts}.
 *
 * @internal
 */
interface UiPorts
{
    /**
     * @return array<non-empty-string, int> By the name the UI knows a port by, as `xdebug`; none while off.
     */
    public function uiPorts(): array;
}
