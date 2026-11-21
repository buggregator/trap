<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Frontend;

use Buggregator\Trap\Module\Frontend\UiPorts;
use Buggregator\Trap\Module\XDebug\Debugger;

/**
 * The debugger port for the setup guide of the UI, while the debugger is on.
 *
 * @internal
 */
final class Ports implements UiPorts
{
    public function __construct(
        private readonly Debugger $debugger,
    ) {}

    #[\Override]
    public function uiPorts(): array
    {
        return $this->debugger->isEnabled() ? ['xdebug' => $this->debugger->config->port] : [];
    }
}
