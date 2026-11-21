<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Server\Application\Module\Module;

/**
 * A module with a port of its own for the setup guide of the UI: `ports` of `/api/settings`.
 *
 * @internal
 */
interface ProvidesUiPorts extends Module
{
    /**
     * @return class-string<UiPorts> A container singleton.
     */
    public function uiPorts(): string;
}
